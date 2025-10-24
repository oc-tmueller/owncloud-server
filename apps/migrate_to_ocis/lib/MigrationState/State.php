<?php

namespace OCA\MigrateToInfiniteScale\MigrationState;

/**
 * Represents a particular state of the migration.
 */
interface State {
	/**
	 * Run whatever migration actions are needed for this state and move
	 * to the next state.
	 * Moving to the next state is usually done if the migration step is
	 * done correctly. The decision is entirely on the state implementation.
	 *
	 * In order to move to the next state, use
	 * `$migration->switchState(StateSecond::class)`.
	 * Other methods of the migration instance shouldn't be used from the state.
	 *
	 * @param array $params key-value pairs with the parameters to run
	 * the method
	 * @param Migration $migration the migration to switch the state
	 * @throws MigrateException
	 */
	public function migrate(array $params, Migration $migration);

	/**
	 * Return the occ command associated to this state. Running that command
	 * should push the migration forward from this state to the next one.
	 * Note that the association with the command is just for information
	 * and it isn't enforced in any way.
	 *
	 * @return string
	 */
	public function associatedCommand(): string;
}
