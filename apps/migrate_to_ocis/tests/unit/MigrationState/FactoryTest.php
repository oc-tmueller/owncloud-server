<?php
// SPDX-License-Identifier: Apache-2.0

namespace OCA\MigrateToInfiniteScale\Tests\unit\MigrationState;

use OC\Authentication\Token\DefaultTokenProvider;
use OCA\MigrateToInfiniteScale\MigrationState\Factory;
use OCA\MigrateToInfiniteScale\Helper\UserGroupFinder;
use OCA\MigrateToInfiniteScale\Helper\UserHandler;
use OCA\MigrateToInfiniteScale\ConflictLog\LogService;
use OCA\MigrateToInfiniteScale\OCIS\ClientService;
use OCA\MigrateToInfiniteScale\MigrationState\State;
use OCA\MigrateToInfiniteScale\MigrationState\StateInit;
use OCA\MigrateToInfiniteScale\MigrationState\StateVerify;
use OCA\MigrateToInfiniteScale\MigrationState\StateMigrateUsers;
use OCA\MigrateToInfiniteScale\MigrationState\StateAssignRole;
use OCA\MigrateToInfiniteScale\MigrationState\StateMigrateGroups;
use OCA\MigrateToInfiniteScale\MigrationState\StateMigrateFiles;
use OCA\MigrateToInfiniteScale\MigrationState\StateMigrateShares;
use OCA\MigrateToInfiniteScale\MigrationState\StateFinish;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\Share\IManager;
use OCP\AppFramework\Utility\ITimeFactory;

class FactoryTest extends \Test\TestCase {
	/** @var Factory */
	private Factory $factory;
	/** @var IConfig */
	private IConfig $config;
	/** @var IUserManager */
	private IUserManager $userManager;
	/** @var IGroupManager */
	private IGroupManager $groupManager;
	/** @var IManager */
	private IManager $shareManager;
	/** @var UserGroupFinder */
	private UserGroupFinder $userGroupFinder;
	/** @var UserHandler */
	private UserHandler $userHandler;
	/** @var ClientService */
	private ClientService $ocisClientService;
	/** @var LogService */
	private LogService $logService;
	/** @var DefaultTokenProvider */
	private DefaultTokenProvider $tokenProvider;
	/** @var IURLGenerator */
	private IURLGenerator $generator;
	/** @var ITimeFactory */
	private ITimeFactory $timeFactory;

	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(IConfig::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->shareManager = $this->createMock(IManager::class);
		$this->ocisClientService = $this->createMock(ClientService::class);
		$this->userHandler = $this->createMock(UserHandler::class);
		$this->userGroupFinder = $this->createMock(UserGroupFinder::class);
		$this->logService = $this->createMock(LogService::class);
		$this->tokenProvider = $this->createMock(DefaultTokenProvider::class);
		$this->generator = $this->createMock(IURLGenerator::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);

		$this->factory = new Factory(
			$this->config,
			$this->userManager,
			$this->groupManager,
			$this->shareManager,
			$this->ocisClientService,
			$this->userHandler,
			$this->userGroupFinder,
			$this->logService,
			$this->tokenProvider,
			$this->generator,
			$this->timeFactory
		);
	}

	public function testGetInitialState(): void {
		self::assertInstanceOf(StateInit::class, $this->factory->getInitialState());
	}

	public function testGetNewStateMissing(): void {
		self::assertNull($this->factory->getNewState("missing__state"));
	}

	public function testRegisterAndGet(): void {
		$mockState = $this->createMock(State::class);
		$this->factory->registerStateConstructor("fakeFullClassName", function () use ($mockState): State {
			return $mockState;
		});

		// builder will always return the same cached instance
		self::assertSame($mockState, $this->factory->getNewState("fakeFullClassName"));
		self::assertSame($mockState, $this->factory->getNewState("fakeFullClassName"));
	}

	public function testRegisterAndGetBuilder(): void {
		$this->factory->registerStateConstructor("fakeFullClassName", function (): State {
			return $this->createMock(State::class);
		});

		$mock1 = $this->factory->getNewState("fakeFullClassName");
		$mock2 = $this->factory->getNewState("fakeFullClassName");

		// builder will return different instances each time
		self::assertInstanceOf(State::class, $mock1);
		self::assertInstanceOf(State::class, $mock2);
		self::assertNotSame($mock1, $mock2);
	}

	public function registerDefaultsProvider(): array {
		return [
			[StateInit::class],
			[StateVerify::class],
			[StateMigrateUsers::class],
			[StateAssignRole::class],
			[StateMigrateGroups::class],
			[StateMigrateFiles::class],
			[StateMigrateShares::class],
			[StateFinish::class]
		];
	}

	/**
	 * @dataProvider registerDefaultsProvider
	 */
	public function testRegisterDefaults(string $stateClassName): void {
		$this->factory->registerDefaults();

		$instance1 = $this->factory->getNewState($stateClassName);
		$instance2 = $this->factory->getNewState($stateClassName);

		// default builders return different instances
		self::assertNotNull($instance1);
		self::assertNotNull($instance2);
		self::assertInstanceOf($stateClassName, $instance1);
		self::assertInstanceOf($stateClassName, $instance2);
		self::assertNotSame($instance1, $instance2);
	}
}
