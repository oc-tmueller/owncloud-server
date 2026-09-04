<?php
// SPDX-License-Identifier: Apache-2.0

namespace OCA\MigrateToInfiniteScale\Tests\unit\MigrationState;

use OCA\MigrateToInfiniteScale\Helper\UserGroupFinder;
use OCA\MigrateToInfiniteScale\Helper\UserHandler;
use OCA\MigrateToInfiniteScale\MigrationState\Migration;
use OCA\MigrateToInfiniteScale\MigrationState\StateAssignRole;
use OCA\MigrateToInfiniteScale\MigrationState\StateMigrateUsers;
use OCA\MigrateToInfiniteScale\MigrationState\Exceptions\MigrateException;
use OCA\MigrateToInfiniteScale\OCIS\Client;
use OCA\MigrateToInfiniteScale\OCIS\ClientService;
use OCA\MigrateToInfiniteScale\OCIS\ClientException;
use OCP\IUserManager;
use OCP\IUser;
use OCP\Http\Client\IResponse;
use Symfony\Component\Console\Output\OutputInterface;

class StateMigrateUsersTest extends \Test\TestCase {
	/** @var StateMigrateUsers */
	private StateMigrateUsers $stateMigrateUsers;
	/** @var UserGroupFinder */
	private UserGroupFinder $userGroupFinder;
	/** @var UserHandler */
	private UserHandler $userHandler;
	/** @var IUserManager */
	private IUserManager $userManager;
	/** @var ClientService */
	private ClientService $ocisClientService;

	protected function setUp(): void {
		$this->ocisClientService = $this->createMock(ClientService::class);
		$this->userGroupFinder = $this->createMock(UserGroupFinder::class);
		$this->userHandler = $this->createMock(UserHandler::class);
		$this->userManager = $this->createMock(IUserManager::class);

		$this->stateMigrateUsers = new StateMigrateUsers($this->ocisClientService, $this->userHandler, $this->userGroupFinder, $this->userManager);
	}

	public function testMigrate(): void {
		$client = $this->createMock(Client::class);
		$client->method('tokenExchange')->willReturn('NotrandomToken0000');
		$client->method('createUser')->willReturnCallback(function ($admin, $token, IUser $user) {
			// we only need to return the user id for now.
			switch ($user->getUserName()) {
				case "user001":
					return ['id' => 'id_user001'];
				case "user002":
					return ['id' => 'id_user002'];
				case "user003":
					return ['id' => 'id_user003'];
			}
		});
		$this->ocisClientService->method('newOCISClient')->willReturn($client);

		$this->userHandler->method('canBeMigrated')->willReturn(true);

		$user1 = $this->createMock(IUser::class);
		$user1->method('isEnabled')->willReturn(false);
		$user1->method('getUserName')->willReturn('user001');
		$user1->method('getEMailAddress')->willReturn('user001@example.prv');

		$user2 = $this->createMock(IUser::class);
		$user2->method('isEnabled')->willReturn(true);
		$user2->method('getUserName')->willReturn('user002');
		$user2->method('getEMailAddress')->willReturn('user002@example.prv');

		$user3 = $this->createMock(IUser::class);
		$user3->method('isEnabled')->willReturn(true);
		$user3->method('getUserName')->willReturn('user003');
		$user3->method('getEMailAddress')->willReturn('user003@example.prv');

		$users = [$user1, $user2, $user3];

		$this->userManager->method('callForUsers')->willReturnCallback(function ($callback) use ($users) {
			foreach ($users as $user) {
				$callback($user);
			}
		});

		$migration = $this->createMock(Migration::class);
		$migration->expects($this->once())
			->method('switchState')
			->with(StateAssignRole::class);

		$this->userGroupFinder->expects($this->once())->method('saveCache');
		$this->userGroupFinder->expects($this->exactly(3))
			->method('addUserToCache')
			->with(
				$this->logicalOr($user1, $user2, $user3),
				$this->logicalOr('id_user001', 'id_user002', 'id_user003')
			);

		$output = $this->createMock(OutputInterface::class);
		$output->expects($this->atLeastOnce())
			->method('writeln')
			->with(
				$this->logicalOr(
					$this->matchesRegularExpression('/user001.*created/'),
					$this->matchesRegularExpression('/user002.*created/'),
					$this->matchesRegularExpression('/user003.*created/'),
				)
			);

		$params = [
			'adminUser' => 'Madmin',
			'adminPassword' => 'Pamword',
			'output' => $output,
		];
		$this->stateMigrateUsers->migrate($params, $migration);
	}

	public function testMigrateCantBeMigrated(): void {
		$client = $this->createMock(Client::class);
		$client->method('tokenExchange')->willReturn('NotrandomToken0000');
		$client->method('createUser')->willReturnCallback(function ($admin, $token, IUser $user) {
			// we only need to return the user id for now.
			switch ($user->getUserName()) {
				case "user001":
					return ['id' => 'id_user001'];
				case "user002":
					return ['id' => 'id_user002'];
				case "user003":
					return ['id' => 'id_user003'];
			}
		});
		$this->ocisClientService->method('newOCISClient')->willReturn($client);

		$this->userHandler->method('canBeMigrated')->willReturnCallback(function (IUser $user) {
			if ($user->getUserName() === 'user002') {
				return false;
			}
			return true;
		});

		$user1 = $this->createMock(IUser::class);
		$user1->method('isEnabled')->willReturn(false);
		$user1->method('getUserName')->willReturn('user001');
		$user1->method('getEMailAddress')->willReturn('user001@example.prv');

		$user2 = $this->createMock(IUser::class);
		$user2->method('isEnabled')->willReturn(true);
		$user2->method('getUserName')->willReturn('user002');
		$user2->method('getEMailAddress')->willReturn('user002@example.prv');

		$user3 = $this->createMock(IUser::class);
		$user3->method('isEnabled')->willReturn(true);
		$user3->method('getUserName')->willReturn('user003');
		$user3->method('getEMailAddress')->willReturn('user003@example.prv');

		$users = [$user1, $user2, $user3];

		$this->userManager->method('callForUsers')->willReturnCallback(function ($callback) use ($users) {
			foreach ($users as $user) {
				$callback($user);
			}
		});

		$migration = $this->createMock(Migration::class);
		$migration->expects($this->once())
			->method('switchState')
			->with(StateAssignRole::class);

		// skipped user won't be saved in cache
		$this->userGroupFinder->expects($this->once())->method('saveCache');
		$this->userGroupFinder->expects($this->exactly(2))
			->method('addUserToCache')
			->with(
				$this->logicalOr($user1, $user3),
				$this->logicalOr('id_user001', 'id_user003')
			);

		$output = $this->createMock(OutputInterface::class);
		$output->expects($this->atLeastOnce())
			->method('writeln')
			->with(
				$this->logicalOr(
					$this->matchesRegularExpression('/user001.*created/'),
					$this->matchesRegularExpression('/user002.*SKIPPED/'),
					$this->matchesRegularExpression('/user003.*created/'),
				)
			);
		$params = [
			'adminUser' => 'Madmin',
			'adminPassword' => 'Pamword',
			'output' => $output,
		];
		$this->stateMigrateUsers->migrate($params, $migration);
	}

	public function testMigrateNoTokenExchange(): void {
		$this->expectException(MigrateException::class);

		$client = $this->createMock(Client::class);
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(500);
		$response->method('getBody')->willReturn('response body error');
		$client->method('tokenExchange')->willThrowException(new ClientException('exception in the client', 'tokenExchange', $response));

		$this->ocisClientService->method('newOCISClient')->willReturn($client);

		$migration = $this->createMock(Migration::class);
		$migration->expects($this->never())->method('switchState');

		$this->userGroupFinder->expects($this->never())->method('saveCache');

		$params = [
			'adminUser' => 'Madmin',
			'adminPassword' => 'Pamword',
			'output' => $this->createMock(OutputInterface::class),
		];
		$this->stateMigrateUsers->migrate($params, $migration);
	}

	public function testMigrateFailToCache(): void {
		// Failing to save the cache won't cause problems.
		// Same behavior as with the regular migration.
		$client = $this->createMock(Client::class);
		$client->method('tokenExchange')->willReturn('NotrandomToken0000');
		$client->method('createUser')->willReturnCallback(function ($admin, $token, IUser $user) {
			// we only need to return the user id for now.
			switch ($user->getUserName()) {
				case "user001":
					return ['id' => 'id_user001'];
				case "user002":
					return ['id' => 'id_user002'];
				case "user003":
					return ['id' => 'id_user003'];
			}
		});
		$this->ocisClientService->method('newOCISClient')->willReturn($client);

		$this->userHandler->method('canBeMigrated')->willReturn(true);

		$user1 = $this->createMock(IUser::class);
		$user1->method('isEnabled')->willReturn(false);
		$user1->method('getUserName')->willReturn('user001');
		$user1->method('getEMailAddress')->willReturn('user001@example.prv');

		$user2 = $this->createMock(IUser::class);
		$user2->method('isEnabled')->willReturn(true);
		$user2->method('getUserName')->willReturn('user002');
		$user2->method('getEMailAddress')->willReturn('user002@example.prv');

		$user3 = $this->createMock(IUser::class);
		$user3->method('isEnabled')->willReturn(true);
		$user3->method('getUserName')->willReturn('user003');
		$user3->method('getEMailAddress')->willReturn('user003@example.prv');

		$users = [$user1, $user2, $user3];

		$this->userManager->method('callForUsers')->willReturnCallback(function ($callback) use ($users) {
			foreach ($users as $user) {
				$callback($user);
			}
		});

		$migration = $this->createMock(Migration::class);
		$migration->expects($this->once())
			->method('switchState')
			->with(StateAssignRole::class);

		$this->userGroupFinder->expects($this->once())
			->method('saveCache')
			->willThrowException(new \UnexpectedValueException());
		$this->userGroupFinder->expects($this->exactly(3))
			->method('addUserToCache')
			->with(
				$this->logicalOr($user1, $user2, $user3),
				$this->logicalOr('id_user001', 'id_user002', 'id_user003')
			);

		$output = $this->createMock(OutputInterface::class);
		$output->expects($this->atLeastOnce())
			->method('writeln')
			->with(
				$this->logicalOr(
					$this->matchesRegularExpression('/user001.*created/'),
					$this->matchesRegularExpression('/user002.*created/'),
					$this->matchesRegularExpression('/user003.*created/'),
					$this->matchesRegularExpression('/cache.*couldn\'t be saved/i'),
				)
			);

		$params = [
			'adminUser' => 'Madmin',
			'adminPassword' => 'Pamword',
			'output' => $output,
		];
		$this->stateMigrateUsers->migrate($params, $migration);
	}

	public function testMigrateCreateUser409(): void {
		$client = $this->createMock(Client::class);
		$client->method('tokenExchange')->willReturn('NotrandomToken0000');
		$client->method('createUser')->willReturnCallback(function ($admin, $token, IUser $user) {
			// we only need to return the user id for now.
			switch ($user->getUserName()) {
				case "user001":
					return ['id' => 'id_user001'];
				case "user002":
					$response = $this->createMock(IResponse::class);
					$response->method('getStatusCode')->willReturn(409);
					$response->method('getBody')->willReturn('{"error": {"message": "Already exists"}}');
					throw new ClientException('exception in client', 'createUser', $response);
				case "user003":
					return ['id' => 'id_user003'];
			}
		});
		$this->ocisClientService->method('newOCISClient')->willReturn($client);

		$this->userHandler->method('canBeMigrated')->willReturn(true);

		$user1 = $this->createMock(IUser::class);
		$user1->method('isEnabled')->willReturn(false);
		$user1->method('getUserName')->willReturn('user001');
		$user1->method('getEMailAddress')->willReturn('user001@example.prv');

		$user2 = $this->createMock(IUser::class);
		$user2->method('isEnabled')->willReturn(true);
		$user2->method('getUserName')->willReturn('user002');
		$user2->method('getEMailAddress')->willReturn('user002@example.prv');

		$user3 = $this->createMock(IUser::class);
		$user3->method('isEnabled')->willReturn(true);
		$user3->method('getUserName')->willReturn('user003');
		$user3->method('getEMailAddress')->willReturn('user003@example.prv');

		$users = [$user1, $user2, $user3];

		$this->userManager->method('callForUsers')->willReturnCallback(function ($callback) use ($users) {
			foreach ($users as $user) {
				$callback($user);
			}
		});

		$migration = $this->createMock(Migration::class);
		$migration->expects($this->once())
			->method('switchState')
			->with(StateAssignRole::class);

		// exception with user2 doesn't add him to the cache
		$this->userGroupFinder->expects($this->once())->method('saveCache');
		$this->userGroupFinder->expects($this->exactly(2))
			->method('addUserToCache')
			->with(
				$this->logicalOr($user1, $user3),
				$this->logicalOr('id_user001', 'id_user003')
			);

		$output = $this->createMock(OutputInterface::class);
		$output->expects($this->atLeastOnce())
			->method('writeln')
			->with(
				$this->logicalOr(
					$this->matchesRegularExpression('/user001.*created/'),
					$this->matchesRegularExpression('/user002.*Already exists/'),
					$this->matchesRegularExpression('/user003.*created/'),
				)
			);
		$params = [
			'adminUser' => 'Madmin',
			'adminPassword' => 'Pamword',
			'output' => $output,
		];
		$this->stateMigrateUsers->migrate($params, $migration);
	}

	public function testMigrateCreateUser500(): void {
		$this->expectException(MigrateException::class);

		$client = $this->createMock(Client::class);
		$client->method('tokenExchange')->willReturn('NotrandomToken0000');
		$client->method('createUser')->willReturnCallback(function ($admin, $token, IUser $user) {
			// we only need to return the user id for now.
			switch ($user->getUserName()) {
				case "user001":
					return ['id' => 'id_user001'];
				case "user002":
					$response = $this->createMock(IResponse::class);
					$response->method('getStatusCode')->willReturn(500);
					$response->method('getBody')->willReturn('{"error": {"message": "Something blew up"}}');
					throw new ClientException('exception in client', 'createUser', $response);
				case "user003":
					return ['id' => 'id_user003'];
			}
		});
		$this->ocisClientService->method('newOCISClient')->willReturn($client);

		$this->userHandler->method('canBeMigrated')->willReturn(true);

		$user1 = $this->createMock(IUser::class);
		$user1->method('isEnabled')->willReturn(false);
		$user1->method('getUserName')->willReturn('user001');
		$user1->method('getEMailAddress')->willReturn('user001@example.prv');

		$user2 = $this->createMock(IUser::class);
		$user2->method('isEnabled')->willReturn(true);
		$user2->method('getUserName')->willReturn('user002');
		$user2->method('getEMailAddress')->willReturn('user002@example.prv');

		$user3 = $this->createMock(IUser::class);
		$user3->method('isEnabled')->willReturn(true);
		$user3->method('getUserName')->willReturn('user003');
		$user3->method('getEMailAddress')->willReturn('user003@example.prv');

		$users = [$user1, $user2, $user3];

		$this->userManager->method('callForUsers')->willReturnCallback(function ($callback) use ($users) {
			foreach ($users as $user) {
				$callback($user);
			}
		});

		$migration = $this->createMock(Migration::class);
		$migration->expects($this->never())->method('switchState');

		$output = $this->createMock(OutputInterface::class);
		$params = [
			'adminUser' => 'Madmin',
			'adminPassword' => 'Pamword',
			'output' => $output,
		];
		$this->stateMigrateUsers->migrate($params, $migration);
	}

	public function testSkip(): void {
		$this->userGroupFinder->expects($this->once())->method('cleanCache');

		$migration = $this->createMock(Migration::class);
		$migration->expects($this->once())
			->method('switchState')
			->with(StateAssignRole::class);

		$this->stateMigrateUsers->skip([], $migration);
	}

	public function testAssociatedCommand(): void {
		self::assertSame('migrate:to-ocis:migrate:users', $this->stateMigrateUsers->associatedCommand());
	}
}
