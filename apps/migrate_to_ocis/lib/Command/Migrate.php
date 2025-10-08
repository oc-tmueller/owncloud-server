<?php

namespace OCA\MigrateToInfiniteScale\Command;

use JsonException;
use OC\Authentication\Token\DefaultTokenProvider;
use OCA\MigrateToInfiniteScale\Helper\ConflictLogFile;
use OCA\MigrateToInfiniteScale\Helper\OCISClient;
use OCA\MigrateToInfiniteScale\Helper\SharePermissionMapper;
use OCA\MigrateToInfiniteScale\Helper\UserGroupFinder;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IGroup;
use OCP\IUserManager;
use OCP\IGroupManager;
use OCP\Share\IManager;
use OCP\Share\IShare;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Migrate extends CommandBase {
	private IUserManager $userManager;
	private IGroupManager $groupManager;
	private IManager $shareManager;
	private ConflictLogFile $conflict_log_file;
	private UserGroupFinder $userGroupFinder;

	public function __construct(
		IConfig $config,
		IUserManager $userManager,
		IGroupManager $groupManager,
		IManager $shareManager,
		IURLGenerator $generator,
		DefaultTokenProvider $tokenProvider
	) {
		parent::__construct();
		$this->config = $config;
		$this->userManager = $userManager;
		$this->groupManager = $groupManager;
		$this->shareManager = $shareManager;
		$this->generator = $generator;
		$this->tokenProvider = $tokenProvider;
	}

	protected function configure() {
		$this
			->setName('migrate:to-ocis')
			->setDescription('Migrates ownCloud to the configured ocis instance. See also: https://doc.owncloud.com/server/latest/admin_manual/maintenance/migrating_to_ocis.html')
			->addArgument('ocis-admin', InputArgument::REQUIRED)
			->addOption('insecure', 'k');
	}

	/**
	 * @throws JsonException
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$this->output = $output;
		$code = $this->preExecute($input);
		if ($code !== 0) {
			return $code;
		}
		# ensure verify command has been executed
		$instance_verified = $this->config->getAppValue('migrate_to_ocis', 'instance_verified', 'no');
		if ($instance_verified !== 'yes') {
			$this->writeln('<error>Please run migrate:to-ocis:verify first</error>');
			return 1;
		}

		# get user access
		# ensure the ocis instance is reachable
		$this->ocis_admin_user = $input->getArgument('ocis-admin');
		$this->askAdminPassword($input, $output);
		$token = $this->getAdminAccessToken();

		$client = $this->initGraphApi();
		$apps = $client->getApplications($token);
		$chosenAppRole = $this->askForDefaultRole($input, $output, $apps);

		$now = \time();
		$this->conflict_log_file = new ConflictLogFile();
		if (!$this->conflict_log_file->open("migrate-ocis-$now.csv")) {
			$this->writeln("Failed to create conflict file: migrate-ocis-$now.csv");
			return 1;
		}

		// prepare cache
		$this->userGroupFinder = new UserGroupFinder($client, $this->userManager, $this->groupManager);

		# first we create users in ocis
		$this->writeln("Migrating users ...");
		$this->migrateUsers($chosenAppRole[1], $chosenAppRole[0]);

		# migrate the groups
		$this->writeln("Migrating groups ...");
		$this->migrateGroups();

		# copy files over to ocis
		$this->writeln("Migrating files ...");
		$ok = $this->cloneFiles();
		if (!$ok) {
			$this->writeln('<error>Issues did arise when migrating files and folders..</error>');
			$this->writeln("<error>Please review {$this->conflict_log_file->getName()} and fix any issues which have been reported.</error>");
			$this->writeln('');
			$this->writeln("Once resolved please re-run the migration process again.</error>");
			$this->writeln('');
			$this->writeln("Migration will stop here now until no more conflicts exist.</error>");
			return 1;
		}

		# migrate shares
		$this->writeln("Migrating shares ...");
		$this->migrateShares();

		return 0;
	}

	private function shallMigrate(IUser $user): bool {
		$userId = $user->getUID();
		if ($user->getEMailAddress() === null) {
			$this->writeln("<error>No Email for user $userId - it cannot be migrated to ownCloud InfiniteScale!</error>");
			return false;
		}
		if (!$user->isEnabled()) {
			$this->writeln("<warn>Disabled user $userId - it cannot be migrated to ownCloud InfiniteScale!</warn>");
			return false;
		}
		return true;
	}

	private function migrateUsers(string $roleId, string $appId): void {
		$this->userManager->callForUsers(function (IUser $user) use ($roleId, $appId) {
			if ($this->shallMigrate($user)) {
				$this->writeln(" " . $user->getUserName() . "/" . $user->getEMailAddress());
				$this->migrateUser($user, $roleId, $appId);
			}
		});
	}

	private function migrateGroups() {
		$groups = $this->groupManager->search("");
		foreach ($groups as $group) {
			$this->writeln(" {$group->getDisplayName()}");
			$this->migrateGroup($group);
		}
	}

	private function cloneFiles(): bool {
		$ok = true;
		$this->userManager->callForUsers(function (IUser $user) use (&$ok) {
			if ($this->shallMigrate($user)) {
				$this->writeln(" " . $user->getUserName() . "/" . $user->getEMailAddress());
				if (!$this->cloneFilesForUser($user, $this->conflict_log_file)) {
					$ok = false;
				}
			}
		});
		return $ok;
	}

	/**
	 * @throws JsonException
	 */
	private function migrateUser(IUser $user, string $roleId, string $appId): void {
		$username = $user->getUserName();
		$token = $this->getAdminAccessToken();

		$client = $this->initGraphApi();
		$userBody = $client->createUser($token, $user);
		if ($userBody) {
			$client->assignRole($token, $userBody['id'], $roleId, $appId);
			$this->writeln("$username - user created in ownCloud InfiniteScale.");

			// we might need the oCIS' user id later, so cache it now
			$this->userGroupFinder->addUserToCache($user, $userBody['id']);
		} else {
			$this->writeln("$username - user already existing in ownCloud InfiniteScale.");
		}
	}

	private function migrateGroup(IGroup $group) {
		$token = $this->getAdminAccessToken();

		$client = $this->initGraphApi();
		$groupBody = $client->createGroup($token, $group);
		if (!$groupBody) {
			// if the group isn't created, try to find it
			$groupBody = $client->checkGroup($token, $group);
		}

		if ($groupBody) {
			foreach ($group->getUsers() as $user) {
				$username = $user->getUserName();
				$ocisUserId = $this->userGroupFinder->getUser($token, $user);
				if ($ocisUserId === null) {
					$this->writeln("  skipped {$group->getDisplayName()} {$username}");
					continue;
				}

				$result = $client->addMemberToGroup($token, $groupBody['id'], $ocisUserId);
				if ($result) {
					$this->writeln("  added {$group->getDisplayName()} {$username}");
				} else {
					$this->writeln("  FAILED {$group->getDisplayName()} {$username}");
				}
			}
			// add the group to the cache
			$this->userGroupFinder->addGroupToCache($group, $groupBody['id']);
		} else {
			$this->writeln("failed to create group {$group->getDisplayName()}");
		}
	}

	private function migrateShares(): void {
		$token = $this->getAdminAccessToken();

		$client = $this->initGraphApi();
		$roles = $client->getShareRoles($token);
		$permMapper = new SharePermissionMapper($roles);
		$permissionMap = $permMapper->getPermissionMap();

		$this->userManager->callForUsers(function (IUser $user) use ($client, $permMapper, $permissionMap) {
			if ($this->shallMigrate($user)) {
				$this->writeln(" " . $user->getUserName() . "/" . $user->getEMailAddress());
				$this->createSharesForUser($this->shareManager, $user, $this->userGroupFinder, $permissionMap, $client, function (IShare $share, array $response) use ($permMapper) {
					$sharePath = $share->getNode()->getPath();
					$sharedWith = $share->getSharedWith();

					$sharedWithStr = $sharedWith;
					if ($share->getShareType() === \OCP\Share::SHARE_TYPE_USER) {
						$sharedWithStr = "user '$sharedWith'";
					} elseif ($share->getShareType() === \OCP\Share::SHARE_TYPE_GROUP) {
						$sharedWithStr = "group '$sharedWith'";
					}

					if (isset($response['error'])) {
						// if there is an error with the response, show the error and finish the callback
						$this->writeln("  <error>$sharePath (shared with $sharedWithStr) => failed with error: {$response['error']['message']}</error>");
						return;
					}

					$processedData = [];
					foreach ($response['value'] as $item) {
						// expect only one item, but multiple items might be returned
						$rolesDisplayNames = [];
						foreach ($item['roles'] as $roleId) {
							$role = $permMapper->getRoleById($roleId);
							if ($role) {
								$rolesDisplayNames[] = "'{$role['displayName']}'";  // include quotes for better message
							} else {
								$rolesDisplayNames[] = "'{$roleId}'";
							}
						}

						$grantedDisplayName = '';
						if (isset($item['grantedToV2']['user'])) {
							$grantedDisplayName = $item['grantedToV2']['user']['displayName'];
						} elseif (isset($item['grantedToV2']['group'])) {
							$grantedDisplayName = $item['grantedToV2']['group']['displayName'];
						}

						$processedData[] = "created with roles " . \implode(',', $rolesDisplayNames) . " to '$grantedDisplayName'";
					}

					$this->writeln("  $sharePath (shared with $sharedWithStr) => " . \implode(';', $processedData));
				});

				$this->createLinkSharesForUser($this->shareManager, $user, $client, function (IShare $share, array $response) {
					$sharePath = $share->getNode()->getPath();

					if (isset($response['error'])) {
						// if there is an error with the response, show the error and finish the callback
						$this->writeln("  <error>$sharePath (shared via link) => failed with error: {$response['error']['message']}</error>");
						return;
					}

					$this->writeln("  $sharePath (shared via link) => created with type '{$response['link']['type']}' on url '{$response['link']['webUrl']}'");
				});
			}
		});
	}

	private function initGraphApi(): OCISClient {
		$client = \OC::$server->getHTTPClientService()->newClient();
		$webdavCS = \OC::$server->getWebDavClientService();
		return new OCISClient($client, $webdavCS, $this->ocis_host, $this->insecure);
	}
}
