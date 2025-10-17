<?php

namespace OCA\MigrateToInfiniteScale\MigrationState;

use OCA\MigrateToInfiniteScale\Helper\UserGroupFinder;
use OCA\MigrateToInfiniteScale\MigrationState\Migration;
use OCA\MigrateToInfiniteScale\MigrationState\StateMigrateGroups;
use OCA\MigrateToInfiniteScale\OCIS\ClientService;
use OCP\IUserManager;
use OCP\IUser;

class StateMigrateUsers implements State {
	/** @var UserGroupFinder */
	private UserGroupFinder $userGroupFinder;
	/** @var IUserManager */
	private IUserManager $userManager;
	/** @var ClientService */
	private ClientService $ocisClientService;

	public function __construct(ClientService $ocisClientService, UserGroupFinder $userGroupFinder, IUserManager $userManager) {
		$this->ocisClientService = $ocisClientService;
		$this->userGroupFinder = $userGroupFinder;
		$this->userManager = $userManager;
	}

	/**
	 * Required params:
	 * - 'roleId' -> the oCIS' role id that we'll be assigned to each user
	 * - 'appId' -> the oCIS' app id for the role
	 * - 'adminUser' -> the oCIS' admin username
	 * - 'adminPassword' -> the oCIS' admin password (an app token will be generated from it)
	 * - 'output' -> a Symfony's OutputInterface to write messages
	 */
	public function migrate(array $params, Migration $migration) {
		$client = $this->ocisClientService->newOCISClient();
		$token = $client->tokenExchange($params['adminUser'], $params['adminPassword'], $params['adminUser']);
		$params['adminPassword'] = $token;  // replace the admin's password with the token
		$params['client'] = $client;  // include the oCIS client so we don't need to create a new one each time

		$this->userManager->callForUsers(function (IUser $user) use ($params) {
			$output = $params['output'];
			if ($user->getEMailAddress() !== null && $user->isEnabled()) {
				$this->migrateUser($user, $params);
			} else {
				$output->writeln(" {$user->getUserName()}/{$user->getEMailAddress()} <error>SKIPPED</error>");
			}
		});

		$migration->switchState(StateMigrateGroups::class);

		// saving the userGroupFinder cache can be done after the state transition
		try {
			$this->userGroupFinder->saveCache();
		} catch (\UnexpectedValueException $ex) {
			$params['output']->writeln("<comment>Cache for the UserGroupFinder couldn't be saved: {$ex->getMessage()}</comment>");
		}
	}

	private function migrateUser(IUser $user, array $params): void {
		$roleId = $params['roleId'];
		$appId = $params['appId'];
		$adminUser = $params['adminUser'];
		$token = $params['adminPassword'];
		$output = $params['output'];
		$client = $params['client'];

		$username = $user->getUserName();
		$mail = $user->getEMailAddress();

		$userBody = $client->createUser($token, $user);
		if ($userBody) {
			$client->assignRole($token, $userBody['id'], $roleId, $appId);
			$output->writeln("{$username}/{$mail} - user created in ownCloud InfiniteScale.");

			// we might need the oCIS' user id later, so cache it now
			$this->userGroupFinder->addUserToCache($user, $userBody['id']);
		} else {
			$output->writeln("{$username}/{$mail} - user already existing in ownCloud InfiniteScale.");
		}
	}

	public function associatedCommand(): string {
		return 'migrate:to-ocis:migrate:users';
	}
}
