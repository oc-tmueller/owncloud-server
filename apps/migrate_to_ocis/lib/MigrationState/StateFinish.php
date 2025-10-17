<?php

namespace OCA\MigrateToInfiniteScale\MigrationState;

class StateFinish implements State {
	// This is the final state after migrating everything. Nothing else to do.
	public function migrate(array $params, Migration $migration) {
	}

	public function associatedCommand(): string {
		return '';
	}
}
