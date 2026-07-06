<?php
// SPDX-License-Identifier: Apache-2.0

namespace OCA\MigrateToInfiniteScale\Tests\unit\MigrationState;

use OCA\MigrateToInfiniteScale\MigrationState\Migration;
use OCA\MigrateToInfiniteScale\MigrationState\StateInit;
use OCA\MigrateToInfiniteScale\MigrationState\StateVerify;
use OCA\MigrateToInfiniteScale\MigrationState\Exceptions\MigrateException;
use OCA\MigrateToInfiniteScale\MigrationState\Exceptions\UnskippableException;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\Rule\InvocationOrder;

class StateInitTest extends \Test\TestCase {
	/** @var StateInit */
	private StateInit $stateInit;
	/** @var IConfig */
	private IConfig $config;

	private function withMatcher(InvocationOrder $matcher, array $expectedParams, int $parameterColumn) {
		return self::callback(function ($param) use ($matcher, $expectedParams, $parameterColumn) {
			// note: newer versions use "numberOfInvocations" instead of "getInvocationCount"
			return $param === $expectedParams[$matcher->getInvocationCount()-1][$parameterColumn];
		});
	}

	protected function setUp(): void {
		$this->config = $this->createMock(IConfig::class);

		$this->stateInit = new StateInit($this->config);
	}

	public function migrateProvider(): array {
		return [
			[true],
			[false],
		];
	}

	/**
	 * @dataProvider migrateProvider
	 */
	public function testMigrate($insecure): void {
		$expectedParams = [
			['migrate_to_ocis', 'ocis_host', 'test.host'],
			['migrate_to_ocis', 'ocis_host_insecure', $insecure],
		];

		$matcher = $this->exactly(2);
		$this->config
			->expects($matcher)
			->method('setAppValue')
			->with(
				$this->withMatcher($matcher, $expectedParams, 0),
				$this->withMatcher($matcher, $expectedParams, 1),
				$this->withMatcher($matcher, $expectedParams, 2)
			);

		$migration = $this->createMock(Migration::class);
		$migration->expects($this->once())
			->method('switchState')
			->with(StateVerify::class);

		$migrateParams = [
			'force' => true,
			'value' => 'test.host',
			'insecure' => $insecure,
		];
		$this->stateInit->migrate($migrateParams, $migration);
	}

	/**
	 * @dataProvider migrateProvider
	 */
	public function testMigrateNoForce($insecure): void {
		$expectedParams = [
			['migrate_to_ocis', 'ocis_host', 'test.host'],
			['migrate_to_ocis', 'ocis_host_insecure', $insecure],
		];

		$matcher = $this->exactly(2);
		$this->config
			->expects($matcher)
			->method('setAppValue')
			->with(
				$this->withMatcher($matcher, $expectedParams, 0),
				$this->withMatcher($matcher, $expectedParams, 1),
				$this->withMatcher($matcher, $expectedParams, 2)
			);
		$this->config
			->expects($this->once())
			->method('getAppValue')
			->willReturn(null);

		$migration = $this->createMock(Migration::class);
		$migration->expects($this->once())
			->method('switchState')
			->with(StateVerify::class);

		$migrateParams = [
			'force' => false,
			'value' => 'test.host',
			'insecure' => $insecure,
		];
		$this->stateInit->migrate($migrateParams, $migration);
	}

	/**
	 * @dataProvider migrateProvider
	 */
	public function testMigrateNoForceAndExisting($insecure): void {
		$this->expectException(MigrateException::class);

		$this->config->expects($this->never())->method('setAppValue');
		$this->config
			->expects($this->once())
			->method('getAppValue')
			->willReturn('test22.host');

		$migration = $this->createMock(Migration::class);
		$migration->expects($this->never())->method('switchState');

		$migrateParams = [
			'force' => false,
			'value' => 'test.host',
			'insecure' => $insecure,
		];
		$this->stateInit->migrate($migrateParams, $migration);
	}

	public function testSkip(): void {
		$this->expectException(UnskippableException::class);
		$this->stateInit->skip([], $this->createMock(Migration::class));
	}

	public function testAssociatedCommand(): void {
		self::assertSame("migrate:to-ocis:init", $this->stateInit->associatedCommand());
	}
}
