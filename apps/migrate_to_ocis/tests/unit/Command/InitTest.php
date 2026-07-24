<?php
// SPDX-License-Identifier: Apache-2.0

namespace OCA\MigrateToInfiniteScale\Tests\unit\Command;

use OCA\MigrateToInfiniteScale\Command\Init;
use OCA\MigrateToInfiniteScale\MigrationState\Migration;
use OCA\MigrateToInfiniteScale\MigrationState\StateInit;
use OCA\MigrateToInfiniteScale\MigrationState\StateFinish;
use OCA\MigrateToInfiniteScale\MigrationState\State;
use OCA\MigrateToInfiniteScale\MigrationState\Exceptions\VerifyStateException;
use OCA\MigrateToInfiniteScale\MigrationState\Exceptions\UnskippableException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application;

class InitTest extends \Test\TestCase {
	/** @var Migration */
	private Migration $migration;
	/** @var Init */
	private Init $initCommand;

	protected function setUp(): void {
		$this->migration = $this->createMock(Migration::class);

		$this->initCommand = new Init($this->migration);
		$this->initCommand->setApplication(new Application());
		$this->commandTester = new CommandTester($this->initCommand);
	}

	public function testMigrate(): void {
		$stateInit = $this->createMock(StateInit::class);
		$stateInit->method('associatedCommand')->willReturn('migrate:to-ocis:init');
		$stateNotFinish = $this->createMock(State::class);
		$stateNotFinish->method('associatedCommand')->willReturn('missingButNotFinish');

		$this->migration->expects($this->once())->method('loadState');
		$this->migration->expects($this->once())->method('saveState');
		$this->migration->expects($this->exactly(2))
			->method('getState')
			->willReturnCallback(function () use ($stateInit, $stateNotFinish) {
				static $calls = -1;
				$states = [$stateInit, $stateNotFinish];
				$calls++;
				return $states[$calls];
			});
		$this->migration->expects($this->once())->method('runMigration')->with(self::callback(function ($parameters) {
			return $parameters['value'] === 'host.prv' &&
				$parameters['insecure'] === false &&
				$parameters['force'] === false;
		}));

		self::assertSame(0, $this->commandTester->execute(['ocis_host' => 'host.prv']));
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

		self::assertSame(1, $this->commandTester->execute(['ocis_host' => 'host.prv']));
		self::assertStringContainsStringIgnoringCase('Consider to run migrate:current_state_command', $this->commandTester->getDisplay());
	}

	public function testMigrateWrongStateForcedInit(): void {
		$stateWrong = $this->createMock(State::class);
		$stateWrong->method('associatedCommand')->willReturn('migrate:current_state_command');
		$stateNotFinish = $this->createMock(State::class);
		$stateNotFinish->method('associatedCommand')->willReturn('missingButNotFinish');

		$this->migration->expects($this->once())->method('loadState');
		$this->migration->expects($this->once())->method('saveState');
		$this->migration->expects($this->exactly(2))
			->method('getState')
			->willReturnCallback(function () use ($stateWrong, $stateNotFinish) {
				static $calls = -1;
				$states = [$stateWrong, $stateNotFinish];
				$calls++;
				return $states[$calls];
			});
		$this->migration->expects($this->once())->method('runMigration')->with(self::callback(function ($parameters) {
			return $parameters['value'] === 'host.prv' &&
				$parameters['insecure'] === false &&
				$parameters['force'] === true;
		}));

		self::assertSame(0, $this->commandTester->execute(['ocis_host' => 'host.prv', '--force' => true]));
		self::assertStringContainsStringIgnoringCase('Continue the migration with', $this->commandTester->getDisplay());
	}

	public function testMigrateFinish(): void {
		$stateFinish = $this->createMock(StateFinish::class);
		$stateFinish->method('associatedCommand')->willReturn('');

		$this->migration->expects($this->once())->method('loadState');
		$this->migration->expects($this->never())->method('saveState');
		$this->migration->expects($this->exactly(2))->method('getState')->willReturn($stateFinish);
		$this->migration->expects($this->never())->method('runMigration');

		self::assertSame(1, $this->commandTester->execute(['ocis_host' => 'host.prv']));
		self::assertStringContainsStringIgnoringCase('Data migration has ended', $this->commandTester->getDisplay());
	}

	public function testSkip(): void {
		$stateInit = $this->createMock(StateInit::class);
		$stateInit->method('associatedCommand')->willReturn('migrate:to-ocis:init');

		$this->migration->expects($this->once())->method('loadState');
		$this->migration->expects($this->never())->method('saveState');
		$this->migration->expects($this->once())->method('getState')->willReturn($stateInit);
		$this->migration->expects($this->never())->method('runMigration');
		$this->migration->expects($this->once())->method('runSkip')->willThrowException(new UnskippableException());

		self::assertSame(1, $this->commandTester->execute(['ocis_host' => 'host.prv', '--skip' => true]));
		self::assertStringContainsStringIgnoringCase('cannot be skipped', $this->commandTester->getDisplay());
	}
}
