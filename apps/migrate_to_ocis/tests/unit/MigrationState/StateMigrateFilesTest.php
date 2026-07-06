<?php
// SPDX-License-Identifier: Apache-2.0

namespace OCA\MigrateToInfiniteScale\Tests\unit\MigrationState;

use OC\Authentication\Token\DefaultTokenProvider;
use OCA\MigrateToInfiniteScale\ConflictLog\LogFile;
use OCA\MigrateToInfiniteScale\ConflictLog\LogService;
use OCA\MigrateToInfiniteScale\Helper\ProcessOutputLineProcessor;
use OCA\MigrateToInfiniteScale\Helper\UserHandler;
use OCA\MigrateToInfiniteScale\MigrationState\Migration;
use OCA\MigrateToInfiniteScale\MigrationState\StateMigrateFiles;
use OCA\MigrateToInfiniteScale\MigrationState\StateMigrateShares;
use OCA\MigrateToInfiniteScale\MigrationState\Exceptions\MigrateException;
use OCA\MigrateToInfiniteScale\MigrationState\Exceptions\UnskippableException;
use OCA\MigrateToInfiniteScale\OCIS\ClientException;
use OCA\MigrateToInfiniteScale\OCIS\ClientService;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\IUser;
use OCP\IURLGenerator;
use OCP\AppFramework\Utility\ITimeFactory;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use Ramsey\Uuid\Uuid;

class StateMigrateFilesTest extends \Test\TestCase {
	/** @var StateMigrateFiles */
	private StateMigrateFiles $stateMigrateFiles;
	/** @var ClientService */
	private ClientService $ocisClientService;
	/** @var UserHandler */
	private UserHandler $userHandler;
	/** @var IUserManager */
	private IUserManager $userManager;
	/** @var IConfig */
	private IConfig $config;
	/** @var LogService */
	private LogService $logService;
	/** @var DefaultTokenProvider */
	private DefaultTokenProvider $tokenProvider;
	/** @var IURLGenerator */
	private IURLGenerator $generator;
	/** @var ITimeFactory */
	private ITimeFactory $timeFactory;

	protected function setUp(): void {
		$this->ocisClientService = $this->createMock(ClientService::class);
		$this->userHandler = $this->createMock(UserHandler::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->config = $this->createMock(IConfig::class);
		$this->logService = $this->createMock(LogService::class);
		$this->tokenProvider = $this->createMock(DefaultTokenProvider::class);
		$this->generator = $this->createMock(IURLGenerator::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);

		$this->stateMigrateFiles = new StateMigrateFiles(
			$this->ocisClientService,
			$this->userHandler,
			$this->userManager,
			$this->config,
			$this->logService,
			$this->tokenProvider,
			$this->generator,
			$this->timeFactory
		);
	}

	public function testSkip(): void {
		$this->expectException(UnskippableException::class);

		$this->stateMigrateFiles->skip([], $this->createMock(Migration::class));
	}

	public function testAssociatedCommand(): void {
		self::assertSame('migrate:to-ocis:migrate:files', $this->stateMigrateFiles->associatedCommand());
	}
}
