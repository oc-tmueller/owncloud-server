<?php
// SPDX-License-Identifier: Apache-2.0

namespace OCA\MigrateToInfiniteScale\Tests\unit\MigrationState;

use OCA\MigrateToInfiniteScale\Helper\UserGroupFinder;
use OCA\MigrateToInfiniteScale\Helper\UserHandler;
use OCA\MigrateToInfiniteScale\Helper\SharePermissionMapper;
use OCA\MigrateToInfiniteScale\MigrationState\Migration;
use OCA\MigrateToInfiniteScale\MigrationState\StateMigrateShares;
use OCA\MigrateToInfiniteScale\MigrationState\StateFinish;
use OCA\MigrateToInfiniteScale\MigrationState\Exceptions\MigrateException;
use OCA\MigrateToInfiniteScale\MigrationState\Exceptions\UnskippableException;
use OCA\MigrateToInfiniteScale\OCIS\ClientException;
use OCA\MigrateToInfiniteScale\OCIS\ClientService;
use OCA\MigrateToInfiniteScale\OCIS\Client;
use OCA\MigrateToInfiniteScale\OCIS\DavException;
use OCP\IUserManager;
use OCP\IUser;
use OCP\Share\IManager;
use OCP\Share\IShare;
use Symfony\Component\Console\Output\OutputInterface;

class StateMigrateSharesTest extends \Test\TestCase {
	/** @var StateMigrateShares */
	private StateMigrateShares $stateMigrateShares;
	/** @var ClientService */
	private ClientService $ocisClientService;
	/** @var UserHandler */
	private UserHandler $userHandler;
	/** @var UserGroupFinder */
	private UserGroupFinder $userGroupFinder;
	/** @var IUserManager */
	private IUserManager $userManager;
	/** @var IManager */
	private IManager $shareManager;

	protected function setUp(): void {
		$this->ocisClientService = $this->createMock(ClientService::class);
		$this->userHandler = $this->createMock(UserHandler::class);
		$this->userGroupFinder = $this->createMock(UserGroupFinder::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->shareManager = $this->createMock(IManager::class);

		$this->stateMigrateShares = new StateMigrateShares(
			$this->ocisClientService,
			$this->userHandler,
			$this->userGroupFinder,
			$this->userManager,
			$this->shareManager
		);
	}

	public function testSkip(): void {
		$this->expectException(UnskippableException::class);
		$this->stateMigrateShares->skip([], $this->createMock(Migration::class));
	}

	public function testAssociatedCommand(): void {
		self::assertSame('migrate:to-ocis:migrate:shares', $this->stateMigrateShares->associatedCommand());
	}
}
