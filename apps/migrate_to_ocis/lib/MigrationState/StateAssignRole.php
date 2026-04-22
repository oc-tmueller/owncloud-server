<?php
// SPDX-License-Identifier: Apache-2.0

namespace OCA\MigrateToInfiniteScale\MigrationState;

use OCA\MigrateToInfiniteScale\Helper\UserGroupFinder;
use OCA\MigrateToInfiniteScale\Helper\UserHandler;
use OCA\MigrateToInfiniteScale\MigrationState\Migration;
use OCA\MigrateToInfiniteScale\MigrationState\StateMigrateGroups;
use OCA\MigrateToInfiniteScale\MigrationState\Exceptions\MigrateException;
use OCA\MigrateToInfiniteScale\MigrationState\Exceptions\UnskippableException;
use OCA\MigrateToInfiniteScale\OCIS\ClientService;
use OCA\MigrateToInfiniteScale\OCIS\ClientException;
use OCP\IUserManager;
use OCP\IUser;

class StateAssignRole implements State {
	/** @var UserGroupFinder */
	private UserGroupFinder $userGroupFinder;
	/** @var UserHandler */
	private UserHandler $userHandler;
	/** @var IUserManager */
	private IUserManager $userManager;
	/** @var ClientService */
	private ClientService $ocisClientService;

	public function __construct(ClientService $ocisClientService, UserHandler $userHandler, UserGroupFinder $userGroupFinder, IUserManager $userManager) {
		$this->ocisClientService = $ocisClientService;
		$this->userGroupFinder = $userGroupFinder;
		$this->userHandler = $userHandler;
		$this->userManager = $userManager;
	}

	/**
	 * Migrate users from OC10 to oCIS. All the migrated users will have
	 * the same predefined role (previously chosen from what's available
	 * in oCIS)
	 *
	 * Required params:
	 * - 'roleId' -> the oCIS' role id that we'll be assigned to each user
	 * - 'appId' -> the oCIS' app id for the role
	 * - 'adminUser' -> the oCIS' admin username
	 * - 'adminPassword' -> the oCIS' admin password (an app token will be generated from it)
	 * - 'output' -> a Symfony's OutputInterface to write messages
	 *
	 * Move to StateMigrateGroups on success.
	 */
	public function migrate(array $params, Migration $migration) {
		try {
			$this->doMigrate($params, $migration);
		} catch (ClientException $ex) {
			throw new MigrateException("Assign role failed", 0, $ex);
		}
	}

	private function doMigrate(array $params, Migration $migration) {
		$client = $this->ocisClientService->newOCISClient();
		$token = $client->tokenExchange($params['adminUser'], $params['adminPassword'], $params['adminUser']);
		$params['adminPassword'] = $token;  // replace the admin's password with the token
		$params['client'] = $client;  // include the oCIS client so we don't need to create a new one each time

		$this->userManager->callForUsers(function (IUser $user) use ($params) {
			if ($this->userHandler->hasBeenMigrated($params['adminUser'], $params['adminPassword'], $user)) {
				$this->assignRole($user, $params);
			} else {
				'@phan-var array{output:\Symfony\Component\Console\Output\OutputInterface} $params'; // @phpstan-ignore-line
				$params['output']->writeln("{$user->getUserName()}/{$user->getEMailAddress()} - <error>NOT MIGRATED</error>");
			}
		});

		$migration->switchState(StateMigrateGroups::class);

		// saving the userGroupFinder cache can be done after the state transition
		try {
			$this->userGroupFinder->saveCache();
		} catch (\UnexpectedValueException $ex) {
			'@phan-var array{output:\Symfony\Component\Console\Output\OutputInterface} $params'; // @phpstan-ignore-line
			$params['output']->writeln("<comment>Cache for the UserGroupFinder couldn't be saved: {$ex->getMessage()}</comment>");
		}
	}

	private function assignRole(IUser $user, array $params): void {
		$roleId = $params['roleId'];
		$appId = $params['appId'];
		$adminUser = $params['adminUser'];
		$token = $params['adminPassword'];
		$output = $params['output'];
		$client = $params['client'];

		$username = $user->getUserName();
		$mail = $user->getEMailAddress();

		if ($adminUser === $username) {
			$output->writeln("{$username}/{$mail} - ignore role change for admin");
			return;
		}

		$ocisUserId = $this->userGroupFinder->getUser($adminUser, $token, $user);
		if ($ocisUserId === null) {
			$output->writeln("{$username}/{$mail} - <error>NOT FOUND</error>");
			return;
		}

		$client->assignRole($adminUser, $token, $ocisUserId, $roleId, $appId);
		$output->writeln("{$username}/{$mail} - role assigned");
	}

	public function skip(array $params, Migration $migration) {
		throw new UnskippableException("Users must have a role");
	}

	public function associatedCommand(): string {
		return 'migrate:to-ocis:assign-role';
	}
}
