<?php
// SPDX-License-Identifier: Apache-2.0

namespace OCA\MigrateToInfiniteScale\Tests\unit\Command;

use OCA\MigrateToInfiniteScale\Command\MigrateShares;
use OCA\MigrateToInfiniteScale\MigrationState\Migration;
use OCA\MigrateToInfiniteScale\MigrationState\StateMigrateShares;
use OCA\MigrateToInfiniteScale\MigrationState\StateFinish;
use OCA\MigrateToInfiniteScale\MigrationState\State;
use OCA\MigrateToInfiniteScale\MigrationState\Exceptions\VerifyStateException;
use OCA\MigrateToInfiniteScale\MigrationState\Exceptions\UnskippableException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application;

class MigrateSharesTest extends \Test\TestCase {
	/** @var Migration */
	private Migration $migration;
	/** @var MigrateShares */
	private MigrateShares $migrateSharesCommand;

	protected function setUp(): void {
		$this->migration = $this->createMock(Migration::class);

		$this->sharesCommand = new MigrateShares($this->migration);
		$this->sharesCommand->setApplication(new Application());
		$this->commandTester = new CommandTester($this->sharesCommand);
	}

	public function testMigrate(): void {
		$stateShares = $this->createMock(StateMigrateShares::class);
		$stateShares->method('associatedCommand')->willReturn('migrate:to-ocis:migrate:shares');
		$stateNotFinish = $this->createMock(State::class);
		$stateNotFinish->method('associatedCommand')->willReturn('missingButNotFinish');

		$this->migration->expects($this->once())->method('loadState');
		$this->migration->expects($this->once())->method('saveState');
		$this->migration->expects($this->exactly(2))
			->method('getState')
			->willReturnCallback(function () use ($stateShares, $stateNotFinish) {
				static $calls = -1;
				$states = [$stateShares, $stateNotFinish];
				$calls++;
				return $states[$calls];
			});
		$this->migration->expects($this->once())->method('runMigration')->with(self::callback(function ($parameters) {
			return $parameters['adminUser'] === 'admin' &&
				$parameters['adminPassword'] === 'FakeAdminPassword' &&
				$parameters['output'] instanceof OutputInterface;
		}));

		$this->commandTester->setInputs(['FakeAdminPassword']);
		self::assertSame(0, $this->commandTester->execute(['ocis-admin' => 'admin']));
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

		$this->commandTester->setInputs(['FakeAdminPassword']);
		self::assertSame(1, $this->commandTester->execute(['ocis-admin' => 'admin']));
		self::assertStringContainsStringIgnoringCase('Consider to run migrate:current_state_command', $this->commandTester->getDisplay());
	}

	public function testMigrateFinish(): void {
		$stateFinish = $this->createMock(StateFinish::class);
		$stateFinish->method('associatedCommand')->willReturn('');

		$this->migration->expects($this->once())->method('loadState');
		$this->migration->expects($this->never())->method('saveState');
		$this->migration->expects($this->exactly(2))->method('getState')->willReturn($stateFinish);
		$this->migration->expects($this->never())->method('runMigration');

		$this->commandTester->setInputs(['FakeAdminPassword']);
		self::assertSame(1, $this->commandTester->execute(['ocis-admin' => 'admin']));
		self::assertStringContainsStringIgnoringCase('Data migration has ended', $this->commandTester->getDisplay());
	}

	public function testSkip(): void {
		$stateShares = $this->createMock(StateMigrateShares::class);
		$stateShares->method('associatedCommand')->willReturn('migrate:to-ocis:migrate:shares');
		$stateNotFinish = $this->createMock(State::class);
		$stateNotFinish->method('associatedCommand')->willReturn('missingButNotFinish');

		$this->migration->expects($this->once())->method('loadState');
		$this->migration->expects($this->never())->method('saveState');
		$this->migration->expects($this->once())->method('getState')->willReturn($stateShares);
		$this->migration->expects($this->never())->method('runMigration');
		$this->migration->expects($this->once())->method('runSkip')->willThrowException(new UnskippableException());

		$this->commandTester->setInputs(['FakeAdminPassword']);
		self::assertSame(1, $this->commandTester->execute(['ocis-admin' => 'admin', '--skip' => true]));
		self::assertStringContainsStringIgnoringCase('cannot be skipped', $this->commandTester->getDisplay());
	}
}
