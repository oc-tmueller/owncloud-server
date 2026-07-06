<?php
// SPDX-License-Identifier: Apache-2.0

namespace OCA\MigrateToInfiniteScale\Tests\unit\MigrationState;

use OCA\MigrateToInfiniteScale\MigrationState\StateFinish;
use OCA\MigrateToInfiniteScale\MigrationState\Migration;

class StateFinishTest extends \Test\TestCase {
	/** @var StateFinish */
	private StateFinish $stateFinish;

	protected function setUp(): void {
		$this->stateFinish = new StateFinish();
	}

	public function testMigrate(): void {
		$migration = $this->createMock(Migration::class);
		$migration->expects($this->never())->method('switchState');

		$this->stateFinish->migrate([], $migration);
	}

	public function testSkip(): void {
		$migration = $this->createMock(Migration::class);
		$migration->expects($this->never())->method('switchState');

		$this->stateFinish->skip([], $migration);
	}

	public function testAssociatedCommand(): void {
		self::assertSame('', $this->stateFinish->associatedCommand());
	}
}
