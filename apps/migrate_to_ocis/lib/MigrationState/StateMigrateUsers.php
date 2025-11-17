<?php

namespace OCA\MigrateToInfiniteScale\MigrationState;

use OCA\MigrateToInfiniteScale\Helper\UserGroupFinder;
use OCA\MigrateToInfiniteScale\Helper\UserHandler;
use OCA\MigrateToInfiniteScale\MigrationState\Migration;
use OCA\MigrateToInfiniteScale\MigrationState\StateAssignRole;
use OCA\MigrateToInfiniteScale\MigrationState\Exceptions\MigrateException;
use OCA\MigrateToInfiniteScale\OCIS\ClientService;
use OCA\MigrateToInfiniteScale\OCIS\ClientException;
use OCP\IUserManager;
use OCP\IUser;

class StateMigrateUsers implements State {
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
			throw new MigrateException("Migrating users failed", 0, $ex);
		}
	}

	private function doMigrate(array $params, Migration $migration) {
		$client = $this->ocisClientService->newOCISClient();
		$token = $client->tokenExchange($params['adminUser'], $params['adminPassword'], $params['adminUser']);
		$params['adminPassword'] = $token;  // replace the admin's password with the token
		$params['client'] = $client;  // include the oCIS client so we don't need to create a new one each time

		$this->userManager->callForUsers(function (IUser $user) use ($params) {
			if ($this->userHandler->canBeMigrated($user)) {
				$this->migrateUser($user, $params);
			} else {
				'@phan-var array{output:\Symfony\Component\Console\Output\OutputInterface} $params'; // @phpstan-ignore-line
				$params['output']->writeln("{$user->getUserName()}/{$user->getEMailAddress()} - <error>SKIPPED</error>");
			}
		});

		$migration->switchState(StateAssignRole::class);

		// saving the userGroupFinder cache can be done after the state transition
		try {
			$this->userGroupFinder->saveCache();
		} catch (\UnexpectedValueException $ex) {
			'@phan-var array{output:\Symfony\Component\Console\Output\OutputInterface} $params'; // @phpstan-ignore-line
			$params['output']->writeln("<comment>Cache for the UserGroupFinder couldn't be saved: {$ex->getMessage()}</comment>");
		}
	}

	private function migrateUser(IUser $user, array $params): void {
		$adminUser = $params['adminUser'];
		$token = $params['adminPassword'];
		$output = $params['output'];
		$client = $params['client'];

		$username = $user->getUserName();
		$mail = $user->getEMailAddress();

		try {
			$userBody = $client->createUser($adminUser, $token, $user);
			$output->writeln("{$username}/{$mail} - user created");

			// we might need the oCIS' user id later, so cache it now
			$this->userGroupFinder->addUserToCache($user, $userBody['id']);
		} catch (ClientException $ex) {
			if ($ex->getCode() === 409) {
				// if there is a 409 in the createUser function, assume the
				// user already exists in oCIS -> show a message and keep going.
				$body = \json_decode($ex->getRawBody(), true);
				$errorMessage = $body['error']['message'] ?? 'unknown error';
				$output->writeln("{$username}/{$mail} - {$errorMessage}");
			} else {
				// rethrow the exception. This includes errors from the
				// createUser and assignRole methods,
				throw $ex;
			}
		}
	}

	public function skip(array $params, Migration $migration) {
		// "migrate" would overwrite the contents of the UserGroupFinder cache;
		// in this case, we'll delete the file to prevent getting wrong data
		// from other migrations that could be left in the cache file.
		$this->userGroupFinder->cleanCache();
		$migration->switchState(StateAssignRole::class);
	}

	public function associatedCommand(): string {
		return 'migrate:to-ocis:migrate:users';
	}
}
