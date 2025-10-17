<?php

namespace OCA\MigrateToInfiniteScale\MigrationState;

use OCA\MigrateToInfiniteScale\Helper\UserGroupFinder;
use OCA\MigrateToInfiniteScale\MigrationState\Migration;
use OCA\MigrateToInfiniteScale\MigrationState\StateMigrateFiles;
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
	 * Required params:
	 * - 'adminUser' -> the oCIS' admin username
	 * - 'adminPassword' -> the oCIS' admin password (an app token will be generated from it)
	 * - 'output' -> a Symfony's OutputInterface to write messages
	 */
	public function migrate(array $params, Migration $migration) {
		$client = $this->ocisClientService->newOCISClient();
		$token = $client->tokenExchange($params['adminUser'], $params['adminPassword'], $params['adminUser']);
		$params['adminPassword'] = $token;  // replace the admin's password with the token
		$params['client'] = $client;  // include the oCIS client so we don't need to create a new one each time
		$output = $params['output'];

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
		$token = $params['adminPassword'];
		$client = $params['client'];
		$output = $params['output'];

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
					$output->writeln("  skipped {$group->getDisplayName()} {$username}");
					continue;
				}

				$result = $client->addMemberToGroup($token, $groupBody['id'], $ocisUserId);
				if ($result) {
					$output->writeln("  added {$group->getDisplayName()} {$username}");
				} else {
					$output->writeln("  FAILED {$group->getDisplayName()} {$username}");
				}
			}
			// add the group to the cache
			$this->userGroupFinder->addGroupToCache($group, $groupBody['id']);
		} else {
			$output->writeln("failed to create group {$group->getDisplayName()}");
		}
	}

	public function associatedCommand(): string {
		return 'migrate:to-ocis:migrate:groups';
	}
}
