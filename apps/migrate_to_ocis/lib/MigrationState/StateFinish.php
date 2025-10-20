<?php

namespace OCA\MigrateToInfiniteScale\MigrationState;

/**
 * This is the final state. If you've reached this state, the migration is done.
 */
class StateFinish implements State {
	// This is the final state after migrating everything. Nothing else to do.
	public function migrate(array $params, Migration $migration) {
	}

	public function associatedCommand(): string {
		return '';
	}
}
