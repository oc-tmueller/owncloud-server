<?php
// SPDX-License-Identifier: Apache-2.0

namespace OCA\MigrateToInfiniteScale\Tests\unit\Command;

use OCA\MigrateToInfiniteScale\Command\Verify;
use OCA\MigrateToInfiniteScale\Helper\Storage;
use OCA\MigrateToInfiniteScale\MigrationState\Migration;
use OCA\MigrateToInfiniteScale\MigrationState\StateVerify;
use OCA\MigrateToInfiniteScale\MigrationState\StateFinish;
use OCA\MigrateToInfiniteScale\MigrationState\State;
use OCA\MigrateToInfiniteScale\MigrationState\Exceptions\VerifyStateException;
use OCA\MigrateToInfiniteScale\MigrationState\Exceptions\UnskippableException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application;

class VerifyTest extends \Test\TestCase {
	/** @var Migration */
	private Migration $migration;
	/** @var Storage */
	private Storage $storage;
	/** @var Verify */
	private Verify $verifyCommand;

	protected function setUp(): void {
		$this->migration = $this->createMock(Migration::class);
		$this->storage = $this->createMock(Storage::class);

		$this->verifyCommand = new Verify($this->migration, $this->storage);
		$this->verifyCommand->setApplication(new Application());
		$this->commandTester = new CommandTester($this->verifyCommand);
	}

	public function testMigrate(): void {
		$stateVerify = $this->createMock(StateVerify::class);
		$stateVerify->method('associatedCommand')->willReturn('migrate:to-ocis:verify');
		$stateNotFinish = $this->createMock(State::class);
		$stateNotFinish->method('associatedCommand')->willReturn('missingButNotFinish');

		$this->migration->expects($this->once())->method('loadState');
		$this->migration->expects($this->once())->method('saveState');
		$this->migration->expects($this->exactly(2))
			->method('getState')
			->willReturnCallback(function () use ($stateVerify, $stateNotFinish) {
				static $calls = -1;
				$states = [$stateVerify, $stateNotFinish];
				$calls++;
				return $states[$calls];
			});
		$this->migration->expects($this->once())->method('runMigration');

		$this->storage->expects($this->once())->method('getUsedTotalSpace')->willReturn(123456789);

		self::assertSame(0, $this->commandTester->execute([]));
		self::assertStringContainsStringIgnoringCase('Continue the migration with', $this->commandTester->getDisplay());
	}

	public function testMigrateWrongState(): void {
		$stateWrong = $this->createMock(State::class);
		$stateWrong->method('associatedCommand')->willReturn('migrate:current_state_command');
		$stateNotFinish = $this->createMock(State::class);
		$stateNotFinish->method('associatedCommand')->willReturn('missingButNotFinish');

		$this->migration->expects($this->once())->method('loadState');
		$this->migration->expects($this->never())->method('saveState');
		$this->migration->expects($this->exactly(2))
			->method('getState')
			->willReturnCallback(function () use ($stateWrong, $stateNotFinish) {
				static $calls = -1;
				$states = [$stateWrong, $stateNotFinish];
				$calls++;
				return $states[$calls];
			});
		$this->migration->expects($this->never())->method('runMigration');

		$this->storage->expects($this->never())->method('getUsedTotalSpace');

		self::assertSame(1, $this->commandTester->execute([]));
		self::assertStringContainsStringIgnoringCase('Consider to run migrate:current_state_command', $this->commandTester->getDisplay());
	}

	public function testMigrateFinish(): void {
		$stateFinish = $this->createMock(StateFinish::class);
		$stateFinish->method('associatedCommand')->willReturn('');

		$this->migration->expects($this->once())->method('loadState');
		$this->migration->expects($this->never())->method('saveState');
		$this->migration->expects($this->exactly(2))->method('getState')->willReturn($stateFinish);
		$this->migration->expects($this->never())->method('runMigration');

		$this->storage->expects($this->never())->method('getUsedTotalSpace');

		self::assertSame(1, $this->commandTester->execute([]));
		self::assertStringContainsStringIgnoringCase('Data migration has ended', $this->commandTester->getDisplay());
	}

	public function testSkip(): void {
		$stateVerify = $this->createMock(StateVerify::class);
		$stateVerify->method('associatedCommand')->willReturn('migrate:to-ocis:verify');
		$stateNotFinish = $this->createMock(State::class);
		$stateNotFinish->method('associatedCommand')->willReturn('missingButNotFinish');

		$this->migration->expects($this->once())->method('loadState');
		$this->migration->expects($this->once())->method('saveState');
		$this->migration->expects($this->exactly(2))
			->method('getState')
			->willReturnCallback(function () use ($stateVerify, $stateNotFinish) {
				static $calls = -1;
				$states = [$stateVerify, $stateNotFinish];
				$calls++;
				return $states[$calls];
			});
		$this->migration->expects($this->never())->method('runMigration');
		$this->migration->expects($this->once())->method('runSkip');

		$this->storage->expects($this->once())->method('getUsedTotalSpace');

		self::assertSame(0, $this->commandTester->execute(['--skip' => true]));
		self::assertStringContainsStringIgnoringCase('Continue the migration with', $this->commandTester->getDisplay());
	}
}
