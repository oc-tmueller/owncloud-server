<?php

namespace OCA\MigrateToInfiniteScale\MigrationState;

use OC\Authentication\Token\DefaultTokenProvider;
use OCA\MigrateToInfiniteScale\Helper\UserGroupFinder;
use OCA\MigrateToInfiniteScale\ConflictLog\LogService;
use OCA\MigrateToInfiniteScale\MigrationState\State;
use OCA\MigrateToInfiniteScale\MigrationState\StateInit;
use OCA\MigrateToInfiniteScale\MigrationState\StateVerify;
use OCA\MigrateToInfiniteScale\MigrationState\StateMigrateUsers;
use OCA\MigrateToInfiniteScale\MigrationState\StateMigrateGroups;
use OCA\MigrateToInfiniteScale\MigrationState\StateMigrateFiles;
use OCA\MigrateToInfiniteScale\MigrationState\StateFinish;
use OCA\MigrateToInfiniteScale\OCIS\ClientService;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\Share\IManager;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * The purpose of this class is to create States so you don't need to deal
 * with the construction internals.
 * You can inject this class as dependency and use "getNewState" with the
 * State's classname to get a preconfigured instance of the State ready to use.
 *
 * The "registerDefaults" method should register all the known State to make
 * them available. Additional States can be registered if needed.
 */
class Factory {
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
	private array $map = [];

	public function __construct(
		IConfig $config,
		IUserManager $userManager,
		IGroupManager $groupManager,
		IManager $shareManager,
		ClientService $ocisClientService,
		UserGroupFinder $userGroupFinder,
		LogService $logService,
		DefaultTokenProvider $tokenProvider,
		IURLGenerator $generator,
		ITimeFactory $timeFactory
	) {
		$this->config = $config;
		$this->userManager = $userManager;
		$this->groupManager = $groupManager;
		$this->shareManager = $shareManager;
		$this->ocisClientService = $ocisClientService;
		$this->userGroupFinder = $userGroupFinder;
		$this->logService = $logService;
		$this->tokenProvider = $tokenProvider;
		$this->generator = $generator;
		$this->timeFactory = $timeFactory;
	}

	/**
	 * Register a builder (callback) method for the $fullClassName.
	 * The builder should return a new instance for the class each
	 * time it is called.
	 *
	 * A simple builder could be:
	 * ```
	 * $builder = function() {
	 *   return new CustomState();
	 * }
	 * ```
	 *
	 * Other builders might be more complex, requiring external references:
	 * ```
	 * $config = $this->config;
	 * $builder = function() use ($config) {
	 *   return new ExtState($config);
	 * }
	 * ```
	 *
	 * The builder must return a State implementation.
	 * @param class-string $fullClassName
	 * @param callable(): State $callback
	 */
	public function registerStateConstructor(string $fullClassName, callable $callback) {
		$this->map[$fullClassName] = $callback;
	}

	/**
	 * Get a new instance of the state registered as $fullClassName
	 * @param class-string $fullClassName
	 * @return State|null
	 */
	public function getNewState(string $fullClassName): ?State {
		$builder = $this->map[$fullClassName] ?? null;
		if ($builder === null) {
			return null;
		}

		return $builder();
	}

	/**
	 * Get the starting state. A state must be returned even if no state
	 * has been registered.
	 * @return State
	 */
	public function getInitialState(): State {
		return new StateInit($this->config);
	}

	/**
	 * Register the constructors for all the known States
	 */
	public function registerDefaults() {
		$data = [
			StateInit::class => function () {
				return new StateInit($this->config);
			},
			StateVerify::class => function () {
				return new StateVerify($this->userManager);
			},
			StateMigrateUsers::class => function () {
				return new StateMigrateUsers($this->ocisClientService, $this->userGroupFinder, $this->userManager);
			},
			StateMigrateGroups::class => function () {
				return new StateMigrateGroups($this->ocisClientService, $this->userGroupFinder, $this->groupManager);
			},
			StateMigrateFiles::class => function () {
				return new StateMigrateFiles(
					$this->ocisClientService,
					$this->userManager,
					$this->config,
					$this->logService,
					$this->tokenProvider,
					$this->generator,
					$this->timeFactory
				);
			},
			StateMigrateShares::class => function () {
				return new StateMigrateShares(
					$this->ocisClientService,
					$this->userGroupFinder,
					$this->userManager,
					$this->shareManager,
				);
			},
			StateFinish::class => function () {
				return new StateFinish();
			}
		];

		foreach ($data as $key => $value) {
			$this->registerStateConstructor($key, $value);
		}
	}
}
