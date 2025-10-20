<?php

namespace OCA\MigrateToInfiniteScale\MigrationState;

use OCA\MigrateToInfiniteScale\MigrationState\Migration;
use OCA\MigrateToInfiniteScale\MigrationState\MigrateException;
use OCA\MigrateToInfiniteScale\MigrationState\StateVerify;
use OCP\IConfig;

/**
 * This is the initial state. This state will save important data to use it later.
 */
class StateInit implements State {
	/** @var IConfig */
	private IConfig $config;

	public function __construct(IConfig $config) {
		$this->config = $config;
	}

	/**
	 * We need to save the oCIS' host and the insecure connection flag
	 * in order to move to the next state.
	 * The host won't be overwritten (and it will throw an exception)
	 * unless it's forced
	 *
	 * Required params:
	 * - 'value' -> the oCIS' host
	 * - 'insecure' -> whether we should verify the oCIS' host certificates
	 * - 'force' -> force the overwritting of the host value
	 *
	 * Move to StateVerify on success.
	 */
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
