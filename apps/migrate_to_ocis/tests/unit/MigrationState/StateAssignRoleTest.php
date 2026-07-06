<?php
// SPDX-License-Identifier: Apache-2.0

namespace OCA\MigrateToInfiniteScale\Tests\unit\MigrationState;

use OCA\MigrateToInfiniteScale\Helper\UserGroupFinder;
use OCA\MigrateToInfiniteScale\Helper\UserHandler;
use OCA\MigrateToInfiniteScale\MigrationState\Migration;
use OCA\MigrateToInfiniteScale\MigrationState\StateAssignRole;
use OCA\MigrateToInfiniteScale\MigrationState\StateMigrateGroups;
use OCA\MigrateToInfiniteScale\MigrationState\Exceptions\MigrateException;
use OCA\MigrateToInfiniteScale\MigrationState\Exceptions\UnskippableException;
use OCA\MigrateToInfiniteScale\OCIS\Client;
use OCA\MigrateToInfiniteScale\OCIS\ClientService;
use OCA\MigrateToInfiniteScale\OCIS\ClientException;
use OCP\IUserManager;
use OCP\IUser;
use OCP\Http\Client\IResponse;
use Symfony\Component\Console\Output\OutputInterface;

class StateAssignRoleTest extends \Test\TestCase {
	/** @var StateAssignRole */
	private StateAssignRole $stateAssignRole;
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

		$this->stateAssignRole = new StateAssignRole($this->ocisClientService, $this->userHandler, $this->userGroupFinder, $this->userManager);
	}

	public function testMigrate(): void {
		$client = $this->createMock(Client::class);
		$client->method('tokenExchange')->willReturn('tok0011kot');
		$client->expects($this->exactly(3))->method('assignRole');

		$this->ocisClientService->method('newOCISClient')->willReturn($client);

		$this->userHandler->method('hasBeenMigrated')->willReturn(true);

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
			->with(StateMigrateGroups::class);

		$this->userGroupFinder->expects($this->once())->method('saveCache');
		$this->userGroupFinder->expects($this->exactly(3))
			->method('getUser')
			->willReturnCallback(function ($adminUser, $token, $user) {
				switch ($user->getUsername()) {
					case "user001":
						return "001oCISuser";
					case "user002":
						return "002oCISuser";
					case "user003":
						return "003oCISuser";
				}
			});

		$output = $this->createMock(OutputInterface::class);
		$output->expects($this->atLeastOnce())
			->method('writeln')
			->with(
				$this->logicalOr(
					$this->matchesRegularExpression('/user001.*role assigned/'),
					$this->matchesRegularExpression('/user002.*role assigned/'),
					$this->matchesRegularExpression('/user003.*role assigned/'),
				)
			);

		$params = [
			'roleId' => 'ocis_role_id_user',
			'appId' => 'ocis_app_id',
			'adminUser' => 'Madmin',
			'adminPassword' => 'Pamword',
			'output' => $output,
		];
		$this->stateAssignRole->migrate($params, $migration);
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
		$this->userGroupFinder->expects($this->never())->method('getUser');

		$params = [
			'roleId' => 'ocis_role_id_user',
			'appId' => 'ocis_app_id',
			'adminUser' => 'Madmin',
			'adminPassword' => 'Pamword',
			'output' => $this->createMock(OutputInterface::class),
		];
		$this->stateAssignRole->migrate($params, $migration);
	}

	public function testMigrateUserNotMigrated(): void {
		$client = $this->createMock(Client::class);
		$client->method('tokenExchange')->willReturn('tok0011kot');
		$client->expects($this->exactly(2))->method('assignRole');

		$this->ocisClientService->method('newOCISClient')->willReturn($client);

		$this->userHandler->method('hasBeenMigrated')->willReturnCallback(function ($admin, $pass, $user) {
			if ($user->getUsername() === 'user002') {
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
			->with(StateMigrateGroups::class);

		$this->userGroupFinder->expects($this->once())->method('saveCache');
		$this->userGroupFinder->expects($this->exactly(2))
			->method('getUser')
			->willReturnCallback(function ($adminUser, $token, $user) {
				switch ($user->getUsername()) {
					case "user001":
						return "001oCISuser";
					case "user002":
						return "002oCISuser";
					case "user003":
						return "003oCISuser";
				}
			});

		$output = $this->createMock(OutputInterface::class);
		$output->expects($this->atLeastOnce())
			->method('writeln')
			->with(
				$this->logicalOr(
					$this->matchesRegularExpression('/user001.*role assigned/'),
					$this->matchesRegularExpression('/user002.*NOT MIGRATED/'),
					$this->matchesRegularExpression('/user003.*role assigned/'),
				)
			);

		$params = [
			'roleId' => 'ocis_role_id_user',
			'appId' => 'ocis_app_id',
			'adminUser' => 'Madmin',
			'adminPassword' => 'Pamword',
			'output' => $output,
		];
		$this->stateAssignRole->migrate($params, $migration);
	}

	public function testMigrateUserNotFound(): void {
		$client = $this->createMock(Client::class);
		$client->method('tokenExchange')->willReturn('tok0011kot');
		$client->expects($this->exactly(2))->method('assignRole');

		$this->ocisClientService->method('newOCISClient')->willReturn($client);

		$this->userHandler->method('hasBeenMigrated')->willReturn(true);

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
			->with(StateMigrateGroups::class);

		$this->userGroupFinder->expects($this->once())->method('saveCache');
		$this->userGroupFinder->expects($this->exactly(3))
			->method('getUser')
			->willReturnCallback(function ($adminUser, $token, $user) {
				switch ($user->getUsername()) {
					case "user001":
						return "001oCISuser";
					case "user002":
						return null;
					case "user003":
						return "003oCISuser";
				}
			});

		$output = $this->createMock(OutputInterface::class);
		$output->expects($this->atLeastOnce())
			->method('writeln')
			->with(
				$this->logicalOr(
					$this->matchesRegularExpression('/user001.*role assigned/'),
					$this->matchesRegularExpression('/user002.*NOT FOUND/'),
					$this->matchesRegularExpression('/user003.*role assigned/'),
				)
			);

		$params = [
			'roleId' => 'ocis_role_id_user',
			'appId' => 'ocis_app_id',
			'adminUser' => 'Madmin',
			'adminPassword' => 'Pamword',
			'output' => $output,
		];
		$this->stateAssignRole->migrate($params, $migration);
	}

	public function testMigrateWithAdmin(): void {
		$client = $this->createMock(Client::class);
		$client->method('tokenExchange')->willReturn('tok0011kot');
		$client->expects($this->exactly(2))->method('assignRole');

		$this->ocisClientService->method('newOCISClient')->willReturn($client);

		$this->userHandler->method('hasBeenMigrated')->willReturn(true);

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
			->with(StateMigrateGroups::class);

		$this->userGroupFinder->expects($this->once())->method('saveCache');
		$this->userGroupFinder->expects($this->exactly(2))
			->method('getUser')
			->willReturnCallback(function ($adminUser, $token, $user) {
				switch ($user->getUsername()) {
					case "user001":
						return "001oCISuser";
					case "user002":
						return "002oCISuser";
					case "user003":
						return "003oCISuser";
				}
			});

		$output = $this->createMock(OutputInterface::class);
		$output->expects($this->atLeastOnce())
			->method('writeln')
			->with(
				$this->logicalOr(
					$this->matchesRegularExpression('/user001.*role assigned/'),
					$this->matchesRegularExpression('/user002.*ignore role change/'),
					$this->matchesRegularExpression('/user003.*role assigned/'),
				)
			);

		$params = [
			'roleId' => 'ocis_role_id_user',
			'appId' => 'ocis_app_id',
			'adminUser' => 'user002',
			'adminPassword' => 'Pamword',
			'output' => $output,
		];
		$this->stateAssignRole->migrate($params, $migration);
	}

	public function testMigrateNoCacheSaved(): void {
		// it will behave as a regular migration
		$client = $this->createMock(Client::class);
		$client->method('tokenExchange')->willReturn('tok0011kot');
		$client->expects($this->exactly(3))->method('assignRole');

		$this->ocisClientService->method('newOCISClient')->willReturn($client);

		$this->userHandler->method('hasBeenMigrated')->willReturn(true);

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
			->with(StateMigrateGroups::class);

		$this->userGroupFinder->expects($this->once())
			->method('saveCache')
			->willThrowException(new \UnexpectedValueException());
		$this->userGroupFinder->expects($this->exactly(3))
			->method('getUser')
			->willReturnCallback(function ($adminUser, $token, $user) {
				switch ($user->getUsername()) {
					case "user001":
						return "001oCISuser";
					case "user002":
						return "002oCISuser";
					case "user003":
						return "003oCISuser";
				}
			});

		$output = $this->createMock(OutputInterface::class);
		$output->expects($this->atLeastOnce())
			->method('writeln')
			->with(
				$this->logicalOr(
					$this->matchesRegularExpression('/user001.*role assigned/'),
					$this->matchesRegularExpression('/user002.*role assigned/'),
					$this->matchesRegularExpression('/user003.*role assigned/'),
					$this->matchesRegularExpression('/cache.*couldn\'t be saved/i'),
				)
			);

		$params = [
			'roleId' => 'ocis_role_id_user',
			'appId' => 'ocis_app_id',
			'adminUser' => 'Madmin',
			'adminPassword' => 'Pamword',
			'output' => $output,
		];
		$this->stateAssignRole->migrate($params, $migration);
	}

	public function testSkip(): void {
		$this->expectException(UnskippableException::class);
		$this->stateAssignRole->skip([], $this->createMock(Migration::class));
	}

	public function testAssociatedCommand(): void {
		self::assertSame('migrate:to-ocis:assign-role', $this->stateAssignRole->associatedCommand());
	}
}
