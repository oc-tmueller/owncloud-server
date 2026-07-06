<?php
// SPDX-License-Identifier: Apache-2.0

namespace OCA\MigrateToInfiniteScale\Tests\unit\MigrationState;

use OCA\MigrateToInfiniteScale\Helper\UserGroupFinder;
use OCA\MigrateToInfiniteScale\MigrationState\Migration;
use OCA\MigrateToInfiniteScale\MigrationState\StateMigrateFiles;
use OCA\MigrateToInfiniteScale\MigrationState\StateMigrateGroups;
use OCA\MigrateToInfiniteScale\MigrationState\Exceptions\MigrateException;
use OCA\MigrateToInfiniteScale\OCIS\ClientException;
use OCA\MigrateToInfiniteScale\OCIS\ClientService;
use OCA\MigrateToInfiniteScale\OCIS\Client;
use OCP\IGroupManager;
use OCP\IGroup;
use OCP\IUser;
use OCP\Http\Client\IResponse;
use Symfony\Component\Console\Output\OutputInterface;

class StateMigrateGroupsTest extends \Test\TestCase {
	/** @var StateMigrateGroups */
	private StateMigrateGroups $stateMigrateGroups;
	/** @var UserGroupFinder */
	private UserGroupFinder $userGroupFinder;
	/** @var IGroupManager */
	private IGroupManager $groupManager;
	/** @var ClientService */
	private ClientService $ocisClientService;

	protected function setUp(): void {
		$this->ocisClientService = $this->createMock(ClientService::class);
		$this->userGroupFinder = $this->createMock(UserGroupFinder::class);
		$this->groupManager = $this->createMock(IGroupManager::class);

		$this->stateMigrateGroups = new StateMigrateGroups($this->ocisClientService, $this->userGroupFinder, $this->groupManager);
	}

	public function testMigrate(): void {
		$client = $this->createMock(Client::class);
		$client->method('tokenExchange')->willReturn('tok0011kot');
		$client->expects($this->exactly(2))->method('createGroup')->willReturnCallback(function ($admin, $token, $group) {
			switch ($group->getDisplayName()) {
				case "GG11":
					return ['id' => 'ocis_1G'];
				case "GG22":
					return ['id' => 'ocis_2G'];
			}
		});
		$client->expects($this->exactly(3))->method('addMemberToGroup');

		$this->ocisClientService->method('newOCISClient')->willReturn($client);

		$this->userGroupFinder->expects($this->once())->method('loadCache');
		$this->userGroupFinder->expects($this->once())->method('saveCache');
		$this->userGroupFinder->expects($this->exactly(3))
			->method('getUser')
			->willReturnCallback(function ($admin, $token, $user) {
				switch ($user->getUserName()) {
					case "user001":
						return 'ocisUser_01';
					case "user002":
						return 'ocisUser_02';
				}
			});
		$this->userGroupFinder->expects($this->exactly(2))->method('addGroupToCache');

		$user1 = $this->createMock(IUser::class);
		$user1->method('getUserName')->willReturn('user001');

		$user2 = $this->createMock(IUser::class);
		$user2->method('getUserName')->willReturn('user002');

		$group1 = $this->createMock(IGroup::class);
		$group1->method('getDisplayName')->willReturn('GG11');
		$group1->method('getUsers')->willReturn([$user1, $user2]);

		$group2 = $this->createMock(IGroup::class);
		$group2->method('getDisplayName')->willReturn('GG22');
		$group2->method('getUsers')->willReturn([$user1]);

		$this->groupManager->method('search')->willReturn([$group1, $group2]);

		$migration = $this->createMock(Migration::class);
		$migration->expects($this->once())
			->method('switchState')
			->with(StateMigrateFiles::class);

		$output = $this->createMock(OutputInterface::class);
		$params = [
			'adminUser' => 'Madmin',
			'adminPassword' => 'Pamword',
			'output' => $output,
		];
		$this->stateMigrateGroups->migrate($params, $migration);
	}

	public function testMigrateNoCache(): void {
		// both load/save cache throws exceptions, but it doesn't affect the behavior
		$client = $this->createMock(Client::class);
		$client->method('tokenExchange')->willReturn('tok0011kot');
		$client->expects($this->exactly(2))->method('createGroup')->willReturnCallback(function ($admin, $token, $group) {
			switch ($group->getDisplayName()) {
				case "GG11":
					return ['id' => 'ocis_1G'];
				case "GG22":
					return ['id' => 'ocis_2G'];
			}
		});
		$client->expects($this->exactly(3))->method('addMemberToGroup');

		$this->ocisClientService->method('newOCISClient')->willReturn($client);

		$this->userGroupFinder->expects($this->once())->method('loadCache')->willThrowException(new \UnexpectedValueException());
		$this->userGroupFinder->expects($this->once())->method('saveCache')->willThrowException(new \UnexpectedValueException());
		$this->userGroupFinder->expects($this->exactly(3))
			->method('getUser')
			->willReturnCallback(function ($admin, $token, $user) {
				switch ($user->getUserName()) {
					case "user001":
						return 'ocisUser_01';
					case "user002":
						return 'ocisUser_02';
				}
			});
		$this->userGroupFinder->expects($this->exactly(2))->method('addGroupToCache');

		$user1 = $this->createMock(IUser::class);
		$user1->method('getUserName')->willReturn('user001');

		$user2 = $this->createMock(IUser::class);
		$user2->method('getUserName')->willReturn('user002');

		$group1 = $this->createMock(IGroup::class);
		$group1->method('getDisplayName')->willReturn('GG11');
		$group1->method('getUsers')->willReturn([$user1, $user2]);

		$group2 = $this->createMock(IGroup::class);
		$group2->method('getDisplayName')->willReturn('GG22');
		$group2->method('getUsers')->willReturn([$user1]);

		$this->groupManager->method('search')->willReturn([$group1, $group2]);

		$migration = $this->createMock(Migration::class);
		$migration->expects($this->once())
			->method('switchState')
			->with(StateMigrateFiles::class);

		$output = $this->createMock(OutputInterface::class);
		$params = [
			'adminUser' => 'Madmin',
			'adminPassword' => 'Pamword',
			'output' => $output,
		];
		$this->stateMigrateGroups->migrate($params, $migration);
	}

	public function testMigrateGroupExists(): void {
		$client = $this->createMock(Client::class);
		$client->method('tokenExchange')->willReturn('tok0011kot');
		$client->expects($this->exactly(2))->method('createGroup')->willReturnCallback(function ($admin, $token, $group) {
			switch ($group->getDisplayName()) {
				case "GG11":
					return ['id' => 'ocis_1G'];
				case "GG22":
					$response = $this->createMock(IResponse::class);
					$response->method('getStatusCode')->willReturn(409);
					$response->method('getBody')->willReturn('error body response');
					throw new ClientException('group exists', 'createGroup', $response);
			}
		});
		$client->expects($this->once())->method('checkGroup')->willReturn(['id' => 'ocis_2G']);
		$client->expects($this->exactly(3))->method('addMemberToGroup');

		$this->ocisClientService->method('newOCISClient')->willReturn($client);

		$this->userGroupFinder->expects($this->once())->method('loadCache');
		$this->userGroupFinder->expects($this->once())->method('saveCache');
		$this->userGroupFinder->expects($this->exactly(3))
			->method('getUser')
			->willReturnCallback(function ($admin, $token, $user) {
				switch ($user->getUserName()) {
					case "user001":
						return 'ocisUser_01';
					case "user002":
						return 'ocisUser_02';
				}
			});
		$this->userGroupFinder->expects($this->exactly(2))->method('addGroupToCache');

		$user1 = $this->createMock(IUser::class);
		$user1->method('getUserName')->willReturn('user001');

		$user2 = $this->createMock(IUser::class);
		$user2->method('getUserName')->willReturn('user002');

		$group1 = $this->createMock(IGroup::class);
		$group1->method('getDisplayName')->willReturn('GG11');
		$group1->method('getUsers')->willReturn([$user1, $user2]);

		$group2 = $this->createMock(IGroup::class);
		$group2->method('getDisplayName')->willReturn('GG22');
		$group2->method('getUsers')->willReturn([$user1]);

		$this->groupManager->method('search')->willReturn([$group1, $group2]);

		$migration = $this->createMock(Migration::class);
		$migration->expects($this->once())
			->method('switchState')
			->with(StateMigrateFiles::class);

		$output = $this->createMock(OutputInterface::class);
		$params = [
			'adminUser' => 'Madmin',
			'adminPassword' => 'Pamword',
			'output' => $output,
		];
		$this->stateMigrateGroups->migrate($params, $migration);
	}

	public function testMigrateGroupFailed(): void {
		$this->expectException(MigrateException::class);

		$client = $this->createMock(Client::class);
		$client->method('tokenExchange')->willReturn('tok0011kot');
		$client->expects($this->exactly(2))->method('createGroup')->willReturnCallback(function ($admin, $token, $group) {
			switch ($group->getDisplayName()) {
				case "GG11":
					return ['id' => 'ocis_1G'];
				case "GG22":
					$response = $this->createMock(IResponse::class);
					$response->method('getStatusCode')->willReturn(500);
					$response->method('getBody')->willReturn('error body response');
					throw new ClientException('group exists', 'createGroup', $response);
			}
		});
		$client->expects($this->never())->method('checkGroup');
		$client->expects($this->exactly(2))->method('addMemberToGroup');

		$this->ocisClientService->method('newOCISClient')->willReturn($client);

		$this->userGroupFinder->expects($this->once())->method('loadCache');
		$this->userGroupFinder->expects($this->never())->method('saveCache');
		$this->userGroupFinder->expects($this->exactly(2))
			->method('getUser')
			->willReturnCallback(function ($admin, $token, $user) {
				switch ($user->getUserName()) {
					case "user001":
						return 'ocisUser_01';
					case "user002":
						return 'ocisUser_02';
				}
			});
		$this->userGroupFinder->expects($this->exactly(1))->method('addGroupToCache');

		$user1 = $this->createMock(IUser::class);
		$user1->method('getUserName')->willReturn('user001');

		$user2 = $this->createMock(IUser::class);
		$user2->method('getUserName')->willReturn('user002');

		$group1 = $this->createMock(IGroup::class);
		$group1->method('getDisplayName')->willReturn('GG11');
		$group1->method('getUsers')->willReturn([$user1, $user2]);

		$group2 = $this->createMock(IGroup::class);
		$group2->method('getDisplayName')->willReturn('GG22');
		$group2->method('getUsers')->willReturn([$user1]);

		$this->groupManager->method('search')->willReturn([$group1, $group2]);

		$migration = $this->createMock(Migration::class);
		$migration->expects($this->never())->method('switchState');

		$output = $this->createMock(OutputInterface::class);
		$params = [
			'adminUser' => 'Madmin',
			'adminPassword' => 'Pamword',
			'output' => $output,
		];
		$this->stateMigrateGroups->migrate($params, $migration);
	}

	public function testMigrateUserSkipped(): void {
		$client = $this->createMock(Client::class);
		$client->method('tokenExchange')->willReturn('tok0011kot');
		$client->expects($this->exactly(2))->method('createGroup')->willReturnCallback(function ($admin, $token, $group) {
			switch ($group->getDisplayName()) {
				case "GG11":
					return ['id' => 'ocis_1G'];
				case "GG22":
					return ['id' => 'ocis_2G'];
			}
		});
		$client->expects($this->exactly(1))->method('addMemberToGroup');

		$this->ocisClientService->method('newOCISClient')->willReturn($client);

		$this->userGroupFinder->expects($this->once())->method('loadCache');
		$this->userGroupFinder->expects($this->once())->method('saveCache');
		$this->userGroupFinder->expects($this->exactly(3))
			->method('getUser')
			->willReturnCallback(function ($admin, $token, $user) {
				switch ($user->getUserName()) {
					case "user001":
						return null;
					case "user002":
						return 'ocisUser_02';
				}
			});
		$this->userGroupFinder->expects($this->exactly(2))->method('addGroupToCache');

		$user1 = $this->createMock(IUser::class);
		$user1->method('getUserName')->willReturn('user001');

		$user2 = $this->createMock(IUser::class);
		$user2->method('getUserName')->willReturn('user002');

		$group1 = $this->createMock(IGroup::class);
		$group1->method('getDisplayName')->willReturn('GG11');
		$group1->method('getUsers')->willReturn([$user1, $user2]);

		$group2 = $this->createMock(IGroup::class);
		$group2->method('getDisplayName')->willReturn('GG22');
		$group2->method('getUsers')->willReturn([$user1]);

		$this->groupManager->method('search')->willReturn([$group1, $group2]);

		$migration = $this->createMock(Migration::class);
		$migration->expects($this->once())
			->method('switchState')
			->with(StateMigrateFiles::class);

		$output = $this->createMock(OutputInterface::class);
		$params = [
			'adminUser' => 'Madmin',
			'adminPassword' => 'Pamword',
			'output' => $output,
		];
		$this->stateMigrateGroups->migrate($params, $migration);
	}

	public function testMigrateUserCannotBeAdded(): void {
		$this->expectException(MigrateException::class);

		$client = $this->createMock(Client::class);
		$client->method('tokenExchange')->willReturn('tok0011kot');
		$client->expects($this->exactly(2))->method('createGroup')->willReturnCallback(function ($admin, $token, $group) {
			switch ($group->getDisplayName()) {
				case "GG11":
					return ['id' => 'ocis_1G'];
				case "GG22":
					return ['id' => 'ocis_2G'];
			}
		});
		$client->expects($this->exactly(3))->method('addMemberToGroup')->willReturnCallback(function ($admin, $token, $groupId, $userId) {
			if ($groupId === 'ocis_2G') {
				$response = $this->createMock(IResponse::class);
				$response->method('getStatusCode')->willReturn(500);
				$response->method('getBody')->willReturn('error body response');
				throw new ClientException('group membership failed', 'addMemberToGroup', $response);
			}
		});

		$this->ocisClientService->method('newOCISClient')->willReturn($client);

		$this->userGroupFinder->expects($this->once())->method('loadCache');
		$this->userGroupFinder->expects($this->never())->method('saveCache');
		$this->userGroupFinder->expects($this->exactly(3))
			->method('getUser')
			->willReturnCallback(function ($admin, $token, $user) {
				switch ($user->getUserName()) {
					case "user001":
						return 'ocisUser_01';
					case "user002":
						return 'ocisUser_02';
				}
			});
		$this->userGroupFinder->expects($this->exactly(1))->method('addGroupToCache');

		$user1 = $this->createMock(IUser::class);
		$user1->method('getUserName')->willReturn('user001');

		$user2 = $this->createMock(IUser::class);
		$user2->method('getUserName')->willReturn('user002');

		$group1 = $this->createMock(IGroup::class);
		$group1->method('getDisplayName')->willReturn('GG11');
		$group1->method('getUsers')->willReturn([$user1, $user2]);

		$group2 = $this->createMock(IGroup::class);
		$group2->method('getDisplayName')->willReturn('GG22');
		$group2->method('getUsers')->willReturn([$user1]);

		$this->groupManager->method('search')->willReturn([$group1, $group2]);

		$migration = $this->createMock(Migration::class);
		$migration->expects($this->never())->method('switchState');

		$output = $this->createMock(OutputInterface::class);
		$params = [
			'adminUser' => 'Madmin',
			'adminPassword' => 'Pamword',
			'output' => $output,
		];
		$this->stateMigrateGroups->migrate($params, $migration);
	}

	public function testSkip(): void {
		$migration = $this->createMock(Migration::class);
		$migration->expects($this->once())
			->method('switchState')
			->with(StateMigrateFiles::class);

		$this->stateMigrateGroups->skip([], $migration);
	}

	public function testAssociatedCommand(): void {
		self::assertSame('migrate:to-ocis:migrate:groups', $this->stateMigrateGroups->associatedCommand());
	}
}
