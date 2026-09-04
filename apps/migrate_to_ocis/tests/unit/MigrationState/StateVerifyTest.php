<?php
// SPDX-License-Identifier: Apache-2.0

namespace OCA\MigrateToInfiniteScale\Tests\unit\MigrationState;

use OCA\MigrateToInfiniteScale\MigrationState\Migration;
use OCA\MigrateToInfiniteScale\MigrationState\StateMigrateUsers;
use OCA\MigrateToInfiniteScale\MigrationState\StateVerify;
use OCA\MigrateToInfiniteScale\MigrationState\Exceptions\MigrateException;
use OCA\MigrateToInfiniteScale\Helper\EMailAddress;
use OCP\IUserManager;
use OCP\IUser;
use Symfony\Component\Console\Output\OutputInterface;

class StateVerifyTest extends \Test\TestCase {
	/** @var StateVerify */
	private StateVerify $stateVerify;
	/** @var IUserManager */
	private IUserManager $userManager;

	protected function setUp(): void {
		$this->userManager = $this->createMock(IUserManager::class);

		$this->stateVerify = new StateVerify($this->userManager);
	}

	public function testMigrate(): void {
		// Disabled users will be skipped and won't be migrated.
		// Migration will proceed normally.
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
			->with(StateMigrateUsers::class);

		$params = [
			'output' => $this->createMock(OutputInterface::class),
		];
		$this->stateVerify->migrate($params, $migration);
	}

	public function testMigrateWrongMail(): void {
		$this->expectException(MigrateException::class);

		// Disabled users will be skipped and won't be migrated.
		$user1 = $this->createMock(IUser::class);
		$user1->method('isEnabled')->willReturn(false);
		$user1->method('getUserName')->willReturn('user001');
		$user1->method('getEMailAddress')->willReturn('user001@example.prv');

		$user2 = $this->createMock(IUser::class);
		$user2->method('isEnabled')->willReturn(true);
		$user2->method('getUserName')->willReturn('user002');
		$user2->method('getEMailAddress')->willReturn('user002');

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
		$migration->expects($this->never())
			->method('switchState')
			->with(StateMigrateUsers::class);

		$params = [
			'output' => $this->createMock(OutputInterface::class),
		];
		$this->stateVerify->migrate($params, $migration);
	}

	public function testMigrateNullMail(): void {
		$this->expectException(MigrateException::class);

		// Disabled users will be skipped and won't be migrated.
		$user1 = $this->createMock(IUser::class);
		$user1->method('isEnabled')->willReturn(false);
		$user1->method('getUserName')->willReturn('user001');
		$user1->method('getEMailAddress')->willReturn('user001@example.prv');

		$user2 = $this->createMock(IUser::class);
		$user2->method('isEnabled')->willReturn(true);
		$user2->method('getUserName')->willReturn('user002');

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
		$migration->expects($this->never())
			->method('switchState')
			->with(StateMigrateUsers::class);

		$params = [
			'output' => $this->createMock(OutputInterface::class),
		];
		$this->stateVerify->migrate($params, $migration);
	}

	public function testMigrateDuplicatedMail(): void {
		$this->expectException(MigrateException::class);

		// Disabled users will be skipped and won't be migrated.
		$user1 = $this->createMock(IUser::class);
		$user1->method('isEnabled')->willReturn(false);
		$user1->method('getUserName')->willReturn('user001');
		$user1->method('getEMailAddress')->willReturn('user001@example.prv');

		$user2 = $this->createMock(IUser::class);
		$user2->method('isEnabled')->willReturn(true);
		$user2->method('getUserName')->willReturn('user002');
		$user2->method('getEMailAddress')->willReturn('user003@example.prv');

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
		$migration->expects($this->never())
			->method('switchState')
			->with(StateMigrateUsers::class);

		$params = [
			'output' => $this->createMock(OutputInterface::class),
		];
		$this->stateVerify->migrate($params, $migration);
	}

	public function testSkip(): void {
		$migration = $this->createMock(Migration::class);
		$migration->expects($this->once())
			->method('switchState')
			->with(StateMigrateUsers::class);

		$this->stateVerify->skip([], $migration);
	}

	public function testAssociatedCommand(): void {
		self::assertSame("migrate:to-ocis:verify", $this->stateVerify->associatedCommand());
	}
}
