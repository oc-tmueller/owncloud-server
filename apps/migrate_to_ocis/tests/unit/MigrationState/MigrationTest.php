<?php
// SPDX-License-Identifier: Apache-2.0

namespace OCA\MigrateToInfiniteScale\Tests\unit\MigrationState;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCA\MigrateToInfiniteScale\MigrationState\Factory;
use OCA\MigrateToInfiniteScale\MigrationState\Migration;
use OCA\MigrateToInfiniteScale\MigrationState\State;
use OCA\MigrateToInfiniteScale\MigrationState\Exceptions\MigrateException;
use OCA\MigrateToInfiniteScale\MigrationState\Exceptions\UnskippableException;

class MigrationTest extends \Test\TestCase {
	/** @var Migration */
	private Migration $migration;
	/** @var IConfig */
	private IConfig $config;
	/** @var ITimeFactory */
	private ITimeFactory $timeFactory;
	/** @var Factory */
	private Factory $factory;
	/** @var State */
	private State $state0;

	protected function setUp(): void {
		$this->factory = $this->createMock(Factory::class);
		$this->config = $this->createMock(IConfig::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);

		// initial state of the migration needs to be mocked here
		$this->state0 = $this->createMock(State::class);
		$this->factory->method('getInitialState')->willReturn($this->state0);

		$this->migration = new Migration($this->factory, $this->config, $this->timeFactory);
	}

	public function testGetInitialState(): void {
		self::assertSame($this->state0, $this->migration->getState());
	}

	public function testSwitchState(): void {
		$newState = $this->createMock(State::class);

		$this->factory
			->expects($this->once())
			->method('getNewState')
			->with('afterInit')
			->willReturn($newState);

		$this->assertTrue($this->migration->switchState('afterInit'));
		$currentState = $this->migration->getState();
		$this->assertSame($newState, $currentState);
		$this->assertNotSame($this->state0, $currentState);
	}

	public function testSwitchStateWrong(): void {
		$this->factory
			->expects($this->once())
			->method('getNewState')
			->willReturn(null);

		$this->assertFalse($this->migration->switchState('missingState'));
		$this->assertSame($this->state0, $this->migration->getState());
	}

	public function testRunMigration(): void {
		$params = ['key1' => 'value1', 'key2' => 'value2'];
		$this->state0
			->expects($this->once())
			->method('migrate')
			->with($params, $this->migration);

		$this->assertNull($this->migration->runMigration($params));
	}

	public function testRunMigrationException(): void {
		$this->expectException(MigrateException::class);
		$this->state0->method('migrate')->willThrowException(new MigrateException());

		$this->migration->runMigration([]);
	}

	public function testRunSkip(): void {
		$params = ['key1' => 'value1', 'key2' => 'value2'];
		$this->state0
			->expects($this->once())
			->method('skip')
			->with($params, $this->migration);

		$this->assertNull($this->migration->runSkip($params));
	}

	public function testRunSkipException(): void {
		$this->expectException(UnskippableException::class);
		$this->state0->method('skip')->willThrowException(new UnskippableException());

		$this->migration->runSkip([]);
	}
}
