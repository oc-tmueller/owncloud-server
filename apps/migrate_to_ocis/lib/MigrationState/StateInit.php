<?php

namespace OCA\MigrateToInfiniteScale\MigrationState;

use OCA\MigrateToInfiniteScale\MigrationState\Migration;
use OCA\MigrateToInfiniteScale\MigrationState\MigrateException;
use OCA\MigrateToInfiniteScale\MigrationState\StateVerify;
use OCP\IConfig;

class StateInit implements State {
	/** @var IConfig */
	private IConfig $config;

	public function __construct(IConfig $config) {
		$this->config = $config;
	}

	public function migrate(array $params, Migration $migration) {
		$force = $params['force'] ?? false;
		$value = $params['value'];
		$insecure = $params['insecure'] ?? false;
		if (!$force) {
			$existing_value = $this->config->getAppValue('migrate_to_ocis', 'ocis_host', null);
			if ($existing_value !== null) {
				$me = new MigrateException('ocis host already set up.');
				$me->setAdvice('You can overwrite the current value by forcing the execution');
				throw $me;
			}
		}

		$this->config->setAppValue('migrate_to_ocis', 'ocis_host', $value);
		$this->config->setAppValue('migrate_to_ocis', 'ocis_host_insecure', $insecure);
		$migration->switchState(StateVerify::class);
	}

	public function associatedCommand(): string {
		return 'migrate:to-ocis:init';
	}
}
