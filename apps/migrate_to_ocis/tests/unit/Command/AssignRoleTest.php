<?php
// SPDX-License-Identifier: Apache-2.0

namespace OCA\MigrateToInfiniteScale\Tests\unit\Command;

use OCA\MigrateToInfiniteScale\Command\AssignRole;
use OCA\MigrateToInfiniteScale\OCIS\Client;
use OCA\MigrateToInfiniteScale\OCIS\ClientService;
use OCA\MigrateToInfiniteScale\MigrationState\Migration;
use OCA\MigrateToInfiniteScale\MigrationState\StateAssignRole;
use OCA\MigrateToInfiniteScale\MigrationState\StateFinish;
use OCA\MigrateToInfiniteScale\MigrationState\State;
use OCA\MigrateToInfiniteScale\MigrationState\Exceptions\VerifyStateException;
use OCA\MigrateToInfiniteScale\MigrationState\Exceptions\UnskippableException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application;

class AssignRoleTest extends \Test\TestCase {
	/** @var Migration */
	private Migration $migration;
	/** @var ClientService */
	private ClientService $clientService;
	/** @var MigrateFiles */
	private MigrateFiles $migrateFilesCommand;

	protected function setUp(): void {
		$this->migration = $this->createMock(Migration::class);
		$this->clientService = $this->createMock(ClientService::class);

		$this->roleCommand = new AssignRole($this->migration, $this->clientService);
		$this->roleCommand->setApplication(new Application());
		$this->commandTester = new CommandTester($this->roleCommand);
	}

	public function testMigrate(): void {
		$stateRoles = $this->createMock(StateAssignRole::class);
		$stateRoles->method('associatedCommand')->willReturn('migrate:to-ocis:assign-roles');
		$stateNotFinish = $this->createMock(State::class);
		$stateNotFinish->method('associatedCommand')->willReturn('missingButNotFinish');

		$client = $this->createMock(Client::class);
		$client->method('tokenExchange')->willReturn('token');
		$client->method('getApplications')->willReturn([
			[
				'displayName' => 'ownCloud Infinite Scale',
				'id' => '9922id',
				'appRoles' => [
					['displayName' => 'User', 'id' => 'd7bee'],
				],
			],
		]);
		$this->clientService->method('newOCISClient')->willReturn($client);

		$this->migration->expects($this->once())->method('loadState');
		$this->migration->expects($this->once())->method('saveState');
		$this->migration->expects($this->exactly(2))
			->method('getState')
			->willReturnCallback(function () use ($stateRoles, $stateNotFinish) {
				static $calls = -1;
				$states = [$stateRoles, $stateNotFinish];
				$calls++;
				return $states[$calls];
			});
		$this->migration->expects($this->once())->method('runMigration')->with(self::callback(function ($parameters) {
			return $parameters['roleId'] === 'd7bee' &&
				$parameters['appId'] === '9922id' &&
				$parameters['adminUser'] === 'admin' &&
				$parameters['adminPassword'] === 'FakeAdminPassword' &&
				$parameters['output'] instanceof OutputInterface;
		}));

		$this->commandTester->setInputs(['FakeAdminPassword', 'User']);
		self::assertSame(0, $this->commandTester->execute(['ocis-admin' => 'admin']));
		self::assertStringContainsStringIgnoringCase('Continue the migration with', $this->commandTester->getDisplay());
	}

	public function testMigrateWrongState(): void {
		$stateWrong = $this->createMock(State::class);
		$stateWrong->method('associatedCommand')->willReturn('migrate:current_state_command');
		$stateNotFinish = $this->createMock(State::class);
		$stateNotFinish->method('associatedCommand')->willReturn('missingButNotFinish');

		$client = $this->createMock(Client::class);
		$client->method('tokenExchange')->willReturn('token');
		$client->method('getApplications')->willReturn([
			[
				'displayName' => 'ownCloud Infinite Scale',
				'id' => '9922id',
				'appRoles' => [
					['displayName' => 'User', 'id' => 'd7bee'],
				],
			],
		]);
		$this->clientService->method('newOCISClient')->willReturn($client);

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

		$this->commandTester->setInputs(['FakeAdminPassword', 'User']);
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
		$stateRoles = $this->createMock(StateAssignRole::class);
		$stateRoles->method('associatedCommand')->willReturn('migrate:to-ocis:assign-role');
		$stateNotFinish = $this->createMock(State::class);
		$stateNotFinish->method('associatedCommand')->willReturn('missingButNotFinish');

		$this->migration->expects($this->once())->method('loadState');
		$this->migration->expects($this->never())->method('saveState');
		$this->migration->expects($this->once())->method('getState')->willReturn($stateRoles);
		$this->migration->expects($this->never())->method('runMigration');
		$this->migration->expects($this->once())->method('runSkip')->willThrowException(new UnskippableException());

		$this->commandTester->setInputs(['FakeAdminPassword']);
		self::assertSame(1, $this->commandTester->execute(['ocis-admin' => 'admin', '--skip' => true]));
		self::assertStringContainsStringIgnoringCase('cannot be skipped', $this->commandTester->getDisplay());
	}
}
