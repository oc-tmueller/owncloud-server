<?php

namespace OCA\MigrateToInfiniteScale\MigrationState;

use OCA\MigrateToInfiniteScale\Helper\UserGroupFinder;
use OCA\MigrateToInfiniteScale\Helper\SharePermissionMapper;
use OCA\MigrateToInfiniteScale\MigrationState\StateFinish;
use OCA\MigrateToInfiniteScale\OCIS\ClientService;
use OCA\MigrateToInfiniteScale\OCIS\Client;
use OCP\IUserManager;
use OCP\IUser;
use OCP\Share\IManager;
use OCP\Share\IShare;

class StateMigrateShares implements State {
	/** @var ClientService */
	private ClientService $ocisClientService;
	/** @var UserGroupFinder */
	private UserGroupFinder $userGroupFinder;
	/** @var IUserManager */
	private IUserManager $userManager;
	/** @var IManager */
	private IManager $shareManager;

	public function __construct(ClientService $ocisClientService, UserGroupFinder $userGroupFinder, IUserManager $userManager, IManager $shareManager) {
		$this->ocisClientService = $ocisClientService;
		$this->userGroupFinder = $userGroupFinder;
		$this->userManager = $userManager;
		$this->shareManager = $shareManager;
	}

	/**
	 * Migrate the shares from OC10 to oCIS. The shares include user shares,
	 * group shares and link shares, for all the users.
	 * This is the last meaningful state of the migration.
	 *
	 * Required params:
	 * - 'adminUser' -> the oCIS' admin username
	 * - 'adminPassword' -> the oCIS' admin password
	 * - 'output' -> a Symfony's OutputInterface to write messages
	 *
	 * Move to StateFinish on success.
	 */
	public function migrate(array $params, Migration $migration) {
		$client = $this->ocisClientService->newOCISClient();
		$params['adminPassword'] = $client->tokenExchange($params['adminUser'], $params['adminPassword'], $params['adminUser']);
		$output = $params['output'];

		$roles = $client->getShareRoles($params['adminPassword']);
		$permMapper = new SharePermissionMapper($roles);
		$permissionMap = $permMapper->getPermissionMap();

		try {
			$this->userGroupFinder->loadCache();
		} catch (\UnexpectedValueException $ex) {
			$output->writeln("<comment>Cache for the UserGroupFinder couldn't be loaded: {$ex->getMessage()}</comment>");
			// we can keep going, albeit slowly
		}

		$this->userManager->callForUsers(function (IUser $user) use ($client, $permMapper, $permissionMap, $params) {
			if ($user->getEMailAddress() !== null && $user->isEnabled()) {
				$output = $params['output'];
				$output->writeln(" " . $user->getUserName() . "/" . $user->getEMailAddress());
				// include the userToken in the params because it will be used
				// in the createSharesForUser and createLinkSharesForUser methods.
				if ($user->getUID() === $params['adminUser']) {
					$params['userToken'] = $params['adminPassword'];  // already got token for the admin
				} else {
					$params['userToken'] = $client->tokenExchange($params['adminUser'], $params['adminPassword'], $user->getUID());
				}

				$this->createSharesForUser($this->shareManager, $user, $this->userGroupFinder, $permissionMap, $client, $params, function (IShare $share, array $response) use ($permMapper, $output) {
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
						$output->writeln("  <error>$sharePath (shared with $sharedWithStr) => failed with error: {$response['error']['message']}</error>");
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

					$output->writeln("  $sharePath (shared with $sharedWithStr) => " . \implode(';', $processedData));
				});

				$this->createLinkSharesForUser($this->shareManager, $user, $client, $params, function (IShare $share, array $response) use ($output) {
					$sharePath = $share->getNode()->getPath();

					if (isset($response['error'])) {
						// if there is an error with the response, show the error and finish the callback
						$output->writeln("  <error>$sharePath (shared via link) => failed with error: {$response['error']['message']}</error>");
						return;
					}

					$output->writeln("  $sharePath (shared via link) => created with type '{$response['link']['type']}' on url '{$response['link']['webUrl']}'");
				});
			}
		});

		$migration->switchState(StateFinish::class);

		// saving the userGroupFinder cache can be done after the state transition
		try {
			$this->userGroupFinder->saveCache();
		} catch (\UnexpectedValueException $ex) {
			$output->writeln("<comment>Cache for the UserGroupFinder couldn't be saved: {$ex->getMessage()}</comment>");
		}
	}

	public function associatedCommand(): string {
		return 'migrate:to-ocis:migrate:shares';
	}

	/**
	 * @param IManager $shareManager
	 * @param IUser $user the OC10 user owning the shares
	 * @param UserGroupFinder $finder to find and cache users and groups
	 * @param array $permissionMap a permission map as generated by SharePermissionMapper->getPermissionMap()
	 * @param OCISClient $ocisClient an oCIS client to perform the needed requests
	 * @param callable $callback a callback to be called after successful share creation.
	 * The method must have the following signature: func(IShare $share, array $jsonResponse)
	 * where the $share is the OC10 share that has been processed and the $jsonReponse is the
	 * oCIS' response to the invite request.
	 */
	private function createSharesForUser(IManager $shareManager, IUser $user, UserGroupFinder $finder, array $permissionMap, Client $ocisClient, array $params, callable $callback) {
		$user_token = $params['userToken'];

		$personalDrives = $ocisClient->getPersonalDrives($user_token, $user->getUID());
		if (\count($personalDrives) !== 1) {
			// only 1 personal drive is expected, abort otherwise
			return false;
		}

		$shares = \array_merge(
			$shareManager->getSharesBy($user->getUID(), \OCP\Share::SHARE_TYPE_USER, null, true, -1),
			$shareManager->getSharesBy($user->getUID(), \OCP\Share::SHARE_TYPE_GROUP, null, true, -1)
		);
		$webdavClient = $ocisClient->getWebdavClientForDrive($user_token, $user->getUID(), $personalDrives[0]);

		foreach ($shares as $share) {
			$nodePath = $share->getNode()->getPath();
			if (\strpos($nodePath, "/{$user->getUID()}/files/") === 0) {
				$nodePath = \substr($nodePath, \strlen("/{$user->getUID()}/files/"));
			}

			$shareExpiration = $share->getExpirationDate();
			if ($shareExpiration) {
				$shareExpiration = $shareExpiration->format(\DateTime::RFC3339);
			}

			$recipientType = '';
			$recipientId = '';
			if ($share->getShareType() === \OCP\Share::SHARE_TYPE_USER) {
				$recipientType = 'user';
				$recipientId = $finder->getUserById($user_token, $share->getSharedWith());
			} elseif ($share->getShareType() === \OCP\Share::SHARE_TYPE_GROUP) {
				$recipientType = 'group';
				$recipientId = $finder->getGroupById($user_token, $share->getSharedWith());
			}

			// from OC10 neither files or folders can be write-only
			// if they're shared with users or groups
			$nodeType = $share->getNodeType();
			$chosenRole = $permissionMap[$nodeType]['ro'];
			if (
				(($share->getPermissions() & \OCP\Constants::PERMISSION_UPDATE) === \OCP\Constants::PERMISSION_UPDATE) ||
				(($share->getPermissions() & \OCP\Constants::PERMISSION_CREATE) === \OCP\Constants::PERMISSION_CREATE)
			) {
				$chosenRole = $permissionMap[$nodeType]['rw'];
			}

			$ocisFileInfo = $ocisClient->getOcisFileInfo($user_token, $webdavClient, $nodePath);
			$inviteData = [
				'driveId' => $personalDrives[0]['id'],
				'itemId' => $ocisFileInfo['{http://owncloud.org/ns}fileid'],
				'recipientType' => $recipientType,
				'recipientId' => $recipientId,
				'roleId' => $chosenRole['id'],
				'expiration' => $shareExpiration,
			];

			$jsonResp = $ocisClient->shareInvite($user_token, $user->getUID(), $inviteData);

			// run callback if successful
			$callback($share, $jsonResp);
		}
	}

	private function createLinkSharesForUser(IManager $shareManager, IUser $user, Client $ocisClient, array $params, callable $callback) {
		$user_token = $params['userToken'];

		$personalDrives = $ocisClient->getPersonalDrives($user_token, $user->getUID());
		if (\count($personalDrives) !== 1) {
			// only 1 personal drive is expected, abort otherwise
			return false;
		}

		$shares = $shareManager->getSharesBy($user->getUID(), \OCP\Share::SHARE_TYPE_LINK, null, true, -1);
		$webdavClient = $ocisClient->getWebdavClientForDrive($user_token, $user->getUID(), $personalDrives[0]);

		foreach ($shares as $share) {
			$nodePath = $share->getNode()->getPath();
			if (\strpos($nodePath, "/{$user->getUID()}/files/") === 0) {
				$nodePath = \substr($nodePath, \strlen("/{$user->getUID()}/files/"));
			}

			$shareExpiration = $share->getExpirationDate();
			if ($shareExpiration) {
				$shareExpiration = $shareExpiration->format(\DateTime::RFC3339);
			}

			$permissions = $share->getPermissions();
			if (($permissions & \OCP\Constants::PERMISSION_READ) === \OCP\Constants::PERMISSION_READ) {
				$ocisLinkType = 'view';
				if (
					($permissions & \OCP\Constants::PERMISSION_UPDATE) === \OCP\Constants::PERMISSION_UPDATE ||
					($permissions & \OCP\Constants::PERMISSION_CREATE) === \OCP\Constants::PERMISSION_CREATE
				) {
					$ocisLinkType = 'edit';
				}
			} else {
				$ocisLinkType = 'createOnly';
			}

			$ocisFileInfo = $ocisClient->getOcisFileInfo($user_token, $webdavClient, $nodePath);
			$linkData = [
				'driveId' => $personalDrives[0]['id'],
				'itemId' => $ocisFileInfo['{http://owncloud.org/ns}fileid'],
				'type' => $ocisLinkType,
				'expiration' => $shareExpiration,
				'password' => $share->getPassword(),
			];

			$jsonResp = $ocisClient->shareLink($user_token, $user->getUID(), $linkData);

			// run callback if successful
			$callback($share, $jsonResp);
		}
	}
}
