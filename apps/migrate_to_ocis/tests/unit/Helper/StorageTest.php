<?php
// SPDX-License-Identifier: Apache-2.0

namespace OCA\MigrateToInfiniteScale\Tests\unit\Helper;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Result;
use OCA\MigrateToInfiniteScale\Helper\Storage;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class StorageTest extends \Test\TestCase {
	/** @var string[] collects every expression passed to selectAlias()/andWhere() */
	private array $selects = [];
	private array $conditions = [];

	/**
	 * @return IDBConnection a connection whose query builder records the
	 * select alias and the where conditions instead of talking to a database
	 */
	private function mockConnection(AbstractPlatform $platform, ?array $row): IDBConnection {
		$this->selects = [];
		$this->conditions = [];

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(function ($x, $y) {
			return "$x = $y";
		});
		$expr->method('gt')->willReturnCallback(function ($x, $y) {
			return "$x > $y";
		});
		$expr->method('literal')->willReturnCallback(function ($input) {
			return (string)$input;
		});

		$result = $this->createMock(Result::class);
		$result->method('fetchAssociative')->willReturn($row);

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createFunction')->willReturnArgument(0);
		$qb->method('selectAlias')->willReturnCallback(function ($select) use ($qb) {
			$this->selects[] = $select;
			return $qb;
		});
		$qb->method('where')->willReturnCallback(function ($predicates) use ($qb) {
			$this->conditions[] = $predicates;
			return $qb;
		});
		$qb->method('andWhere')->willReturnCallback(function ($where) use ($qb) {
			$this->conditions[] = $where;
			return $qb;
		});
		$qb->method('from')->willReturn($qb);
		$qb->method('innerJoin')->willReturn($qb);
		$qb->method('execute')->willReturn($result);

		$connection = $this->createMock(IDBConnection::class);
		$connection->method('getQueryBuilder')->willReturn($qb);
		$connection->method('getDatabasePlatform')->willReturn($platform);

		return $connection;
	}

	public function testGetUsedTotalSpace(): void {
		$storage = new Storage($this->mockConnection(new SqlitePlatform(), ['totalSize' => '123456789']));

		self::assertSame(123456789, $storage->getUsedTotalSpace());
	}

	public function testGetUsedTotalSpaceEmptyInstance(): void {
		// SUM() over no rows yields NULL
		$storage = new Storage($this->mockConnection(new SqlitePlatform(), ['totalSize' => null]));

		self::assertSame(0, $storage->getUsedTotalSpace());
	}

	/**
	 * ownCloud Classic 11 supports sqlite, mysql/mariadb and pgsql only - Oracle
	 * was dropped from the installer and setup in owncloud/core#41555, so it
	 * cannot be reached by this app any more and is not covered here.
	 */
	public function platformProvider(): array {
		return [
			// MySQL and MariaDB have no `||` string concatenation, so they must
			// use CONCAT() - see https://github.com/owncloud/migrate_to_ocis/pull/53
			'mysql' => [new MySQLPlatform(), true],
			'mariadb' => [new MariaDBPlatform(), true],
			'postgres' => [new PostgreSQLPlatform(), false],
			'sqlite' => [new SqlitePlatform(), false],
		];
	}

	/**
	 * @dataProvider platformProvider
	 */
	public function testUserStorageConditionMatchesPlatform(AbstractPlatform $platform, bool $expectConcat): void {
		$storage = new Storage($this->mockConnection($platform, ['totalSize' => '0']));
		$storage->getUsedTotalSpace();

		$condition = \implode("\n", $this->conditions);
		if ($expectConcat) {
			self::assertStringContainsString("CONCAT('%::', `mt`.`user_id`)", $condition);
			self::assertStringNotContainsString("'%::' ||", $condition);
		} else {
			self::assertStringContainsString("'%::' || `mt`.`user_id`", $condition);
			self::assertStringNotContainsString('CONCAT(', $condition);
		}
	}

	/**
	 * @dataProvider platformProvider
	 */
	public function testSizeColumnIsNotQuoted(AbstractPlatform $platform): void {
		$storage = new Storage($this->mockConnection($platform, ['totalSize' => '0']));
		$storage->getUsedTotalSpace();

		// the oracle-style quoting of the reserved `size` word applies to Oracle
		// only, which oc11 no longer supports
		self::assertSame(['SUM(f.size)'], $this->selects);
	}
}
