<?php

namespace OCA\MigrateToInfiniteScale\MigrationState;

use OCA\MigrateToInfiniteScale\Helper\UserGroupFinder;
use OCA\MigrateToInfiniteScale\MigrationState\Migration;
use OCA\MigrateToInfiniteScale\MigrationState\StateMigrateFiles;
use OCA\MigrateToInfiniteScale\OCIS\ClientException;
use OCA\MigrateToInfiniteScale\OCIS\ClientService;
use OCP\IGroupManager;
use OCP\IGroup;

class StateMigrateGroups implements State {
	/** @var UserGroupFinder */
	private UserGroupFinder $userGroupFinder;
	/** @var IGroupManager */
	private IGroupManager $groupManager;
	/** @var ClientService */
	private ClientService $ocisClientService;

	public function __construct(ClientService $ocisClientService, UserGroupFinder $userGroupFinder, IGroupManager $groupManager) {
		$this->ocisClientService = $ocisClientService;
		$this->userGroupFinder = $userGroupFinder;
		$this->groupManager = $groupManager;
	}

	/**
	 * Migrate the OC10 groups to oCIS. The groups will have the same members
	 * assuming the users have been migrated correctly.
	 * Note that the userGroupFinder cache will be saved with the group
	 * information.
	 *
	 * Required params:
	 * - 'adminUser' -> the oCIS' admin username
	 * - 'adminPassword' -> the oCIS' admin password (an app token will be generated from it)
	 * - 'output' -> a Symfony's OutputInterface to write messages
	 *
	 * Move to StateMigrateFiles on success.
	 */
	public function migrate(array $params, Migration $migration) {
		try {
			$this->doMigrate($params, $migration);
		} catch (ClientException $ex) {
			throw new MigrateException("Migrating groups failed", 0, $ex);
		}
	}

	private function doMigrate(array $params, Migration $migration) {
		$client = $this->ocisClientService->newOCISClient();
		$token = $client->tokenExchange($params['adminUser'], $params['adminPassword'], $params['adminUser']);
		$params['adminPassword'] = $token;  // replace the admin's password with the token
		$params['client'] = $client;  // include the oCIS client so we don't need to create a new one each time
		$output = $params['output'];
		'@phan-var \Symfony\Component\Console\Output\OutputInterface $output'; // @phpstan-ignore-line

		try {
			$this->userGroupFinder->loadCache();
		} catch (\UnexpectedValueException $ex) {
			$output->writeln("<comment>Cache for the UserGroupFinder couldn't be loaded: {$ex->getMessage()}</comment>");
			// we can keep going, albeit slowly
		}

		$groups = $this->groupManager->search("");
		foreach ($groups as $group) {
			$output->writeln(" {$group->getDisplayName()}");
			$this->migrateGroup($group, $params);
		}

		$migration->switchState(StateMigrateFiles::class);

		// saving the userGroupFinder cache can be done after the state transition
		try {
			$this->userGroupFinder->saveCache();
		} catch (\UnexpectedValueException $ex) {
			$output->writeln("<comment>Cache for the UserGroupFinder couldn't be saved: {$ex->getMessage()}</comment>");
		}
	}

	private function migrateGroup(IGroup $group, array $params) {
		$adminUser = $params['adminUser'];
		$token = $params['adminPassword'];
		$client = $params['client'];
		$output = $params['output'];

		try {
			$groupBody = $client->createGroup($adminUser, $token, $group);
		} catch (ClientException $ex) {
			if ($ex->getCode() !== 409) {
				// if not 409, rethrow the exception
				throw $ex;
			}
			// if the group isn't created, try to find it
			$groupBody = $client->checkGroup($adminUser, $token, $group);
		}

		if ($groupBody) {
			foreach ($group->getUsers() as $user) {
				$username = $user->getUserName();
				$ocisUserId = $this->userGroupFinder->getUser($adminUser, $token, $user);
				if ($ocisUserId === null) {
					$output->writeln("  {$group->getDisplayName()} {$username} <error>SKIPPED</error>");
					continue;
				}

				try {
					$client->addMemberToGroup($adminUser, $token, $groupBody['id'], $ocisUserId);
					$output->writeln("  {$group->getDisplayName()} {$username} added");
				} catch (ClientException $ex) {
					if ($ex->getCode() !== 409) {
						throw $ex;
					}
					$output->writeln("  {$group->getDisplayName()} {$username} <error>FAILED</error>");
				}
			}
			// add the group to the cache
			$this->userGroupFinder->addGroupToCache($group, $groupBody['id']);
		} else {
			$output->writeln("<error>failed to create group {$group->getDisplayName()}</error>");
		}
	}

	public function associatedCommand(): string {
		return 'migrate:to-ocis:migrate:groups';
	}
}
