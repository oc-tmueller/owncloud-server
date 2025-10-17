<?php

namespace OCA\MigrateToInfiniteScale\MigrationState;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCA\MigrateToInfiniteScale\MigrationState\Factory;
use OCA\MigrateToInfiniteScale\MigrationState\StateInit;

class Migration {
	/** @var State */
	private State $state;
	/** @var IConfig */
	private IConfig $config;
	/** @var ITimeFactory */
	private ITimeFactory $timeFactory;
	/** @var Factory */
	private Factory $factory;

	public function __construct(Factory $factory, IConfig $config, ITimeFactory $timeFactory) {
		$this->factory = $factory;
		$this->config = $config;
		$this->timeFactory = $timeFactory;
		$this->state = $this->factory->getInitialState();
		$this->factory->registerDefaults();  // this should be done in the DI container, but it isn't working.
	}

	/**
	 * Load the previously saved state. The current state loaded into
	 * this migration instance will change only if the loading is
	 * successful.
	 * @return bool true if the saved state is loaded, false otherwise
	 */
	public function loadState(): bool {
		$jsonData = $this->config->getAppValue('migrate_to_ocis', 'state');
		$data = \json_decode($jsonData, true);
		if ($data === null) {
			return false;
		}

		// basic data check
		$savedTime = $data['saved'] ?? 0;
		$savedClass = $data['class'] ?? '';
		$savedCrc = $data['crc'] ?? '';
		$crc = \crc32("{$savedTime}:{$savedClass}");
		if ($crc !== $savedCrc) {
			return false;
		}

		return $this->switchState($savedClass);
	}

	/**
	 * Save the current state of the migration
	 */
	public function saveState() {
		$class = \get_class($this->state);
		$time = $this->timeFactory->getTime();
		$data = [
			'class' => $class,
			'saved' => $time,
			'crc' => \crc32("{$time}:{$class}"),
		];

		$jsonData = \json_encode($data);
		$this->config->setAppValue('migrate_to_ocis', 'state', $jsonData);
	}

	/**
	 * Switch the state of this migration instance
	 * @params class-string $fullClassName the full class name of the state
	 * we want to switch to.
	 * @return bool true if switched, false otherwise.
	 */
	public function switchState(string $fullClassName): bool {
		$state = $this->factory->getNewState($fullClassName);
		if ($state === null) {
			return false;
		}

		$this->state = $state;
		return true;
	}

	/**
	 * Get the current state loaded in this instance
	 * @return State|null
	 */
	public function getState(): ?State {
		return $this->state;
	}

	public function runMigration(array $params) {
		$this->state->migrate($params, $this);
	}
}
