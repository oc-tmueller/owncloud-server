<?php

namespace OCA\MigrateToInfiniteScale;

use OC\Authentication\Token\DefaultTokenProvider;
use OCP\AppFramework\IAppContainer;
use OCP\AppFramework\App;
use OCP\Http\Client\IWebDavClientService;
use OCA\MigrateToInfiniteScale\Helper\UserGroupFinder;
use OCA\MigrateToInfiniteScale\Helper\UserHandler;
use OCA\MigrateToInfiniteScale\MigrationState\Factory;
use OCA\MigrateToInfiniteScale\OCIS\ClientService;
use OCA\MigrateToInfiniteScale\ConflictLog\LogService;

class Application extends App {
	public function __construct($appName, array $urlParams = []) {
		parent::__construct($appName, $urlParams);
		$this->registerServices();
	}

	public function registerServices() {
		$container = $this->getContainer();
		/** @var \OC\Server $server */
		$server = $container->getServer();
		'@phan-var \OC\Server $server'; // @phpstan-ignore-line

		// IWebDavClientService isn't registered as classname in the
		// server, so we need to register it here
		$container->registerService(IWebDavClientService::class, function (IAppContainer $c) use ($server) {
			return $server->getWebDavClientService();
		});

		// we want to register some defaults during creation
		$container->registerService(Factory::class, function (IAppContainer $c) use ($server) {
			$factory = new Factory(
				$server->getConfig(),
				$server->getUserManager(),
				$server->getGroupManager(),
				$server->getShareManager(),
				$c->query(ClientService::class),
				$c->query(UserHandler::class),
				$c->query(UserGroupFinder::class),
				$c->query(LogService::class),
				$server->query(DefaultTokenProvider::class),
				$server->getURLGenerator(),
				$server->getTimeFactory()
			);
			$factory->registerDefaults();
			return $factory;
		});

		$container->registerService(UserGroupFinder::class, function (IAppContainer $c) use ($server) {
			$clientService = $c->query(ClientService::class);
			return new UserGroupFinder(
				$clientService->newOCISClient(),
				$server->getUserManager(),
				$server->getGroupManager(),
				$server->getTempManager()
			);
		});
	}
}
