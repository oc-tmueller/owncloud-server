<?php

namespace OCA\MigrateToInfiniteScale\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use OCA\MigrateToInfiniteScale\MigrationState\Migration;
use OCA\MigrateToInfiniteScale\MigrationState\State;
use OCA\MigrateToInfiniteScale\MigrationState\StateFinish;
use OCA\MigrateToInfiniteScale\MigrationState\VerifyStateException;

abstract class CommandMigration extends CommandBase {
	/** @var Migration */
	private Migration $migration;

	/**
	 * @param Migration $migration;
	 */
	public function __construct(Migration $migration) {
		parent::__construct();
		$this->migration = $migration;
	}

	/**
	 * Prepare the parameter for the migration step. This usually involves
	 * reading console parameters (from the input interface) and eventually
	 * return an array with the parameters for the migration to run.
	 * The migration usually need an output to show messages and progress,
	 * so the provided output interface can be used.
	 *
	 * @params InputInterface $input
	 * @params OutputInterface $output
	 * @return array parameters that will be used for the migration
	 */
	abstract protected function prepareParams(InputInterface $input, OutputInterface $output): array;

	/**
	 * Verify that the provided state (that should be the current migration
	 * state) is the right one for this command.
	 *
	 * If it's the right state, null must be returned; the current migration
	 * state should be run.
	 *
	 * If it's the wrong state, a VerifyStateException must be thrown
	 * explaining the reason; the migration should stop (this isn't
	 * the correct command to be run at the moment).
	 *
	 * Under special circumstances, a different State can be returned. In this
	 * case, the migration will switch to the provided state and run the
	 * migration from there. For example, the "Init" command might be forced
	 * to restart the migration, in that case this method should return the
	 * "StateInit" state.
	 * Note that State must be returned as classname, such as StateInit::class,
	 * and the classname must be registered in the MigrationState\Factory.
	 *
	 * The provided params will be the ones returned by the prepareParams
	 * function. The parameters can be modified here (although not expected).
	 *
	 * @params State $state the current migration state
	 * @params array $params the parameteres returned by prepareParams
	 * @return null|class-string null to run the migration. Returning a string
	 * will switch the migration to that state and run the migration.
	 * @throws VerifyStateException if the verification fails
	 */
	abstract protected function verifyState(State $state, array &$params): ?string;

	/**
	 * Execute actions after the state verification but before the migration runs.
	 * This is a good moment to ask interactively and prepare additional things
	 * for the migration.
	 *
	 * The parameters provided can be modified and new data can be included.
	 * All the parameters will be sent to the migration.
	 *
	 * @params InputInterface $input
	 * @params OutputInterface $output
	 * @params array $params parameters from the prepareParams method that can be
	 * added or modified
	 */
	abstract protected function preMigrateActions(InputInterface $input, OutputInterface $output, array &$params);

	/**
	 * The postSavedActions will be executed after the migration, assuming
	 * the migration finished successfully.
	 */
	abstract protected function postSavedActions(InputInterface $input, OutputInterface $output);

	protected final function execute(InputInterface $input, OutputInterface $output) {
		// prepare the parameters
		$params = $this->prepareParams($input, $output);

		// load the migration
		$this->migration->loadState();

		// verify the state
		$currentState = $this->migration->getState();
		try {
			$result = $this->verifyState($currentState, $params);
			if ($result !== null) {
				$this->migration->switchState($result);
			}
		} catch (VerifyStateException $ex) {
			$output->writeln("<error>Failed to verify the state: {$ex->getMessage()}</error>");

			// if the state is the last one (StateFinish) show a different message
			if (\get_class($this->migration->getState()) === StateFinish::class) {
				$output->writeln('Data migration has ended. There is nothing left to do.');
			} else {
				$output->writeln("Consider to run {$currentState->associatedCommand()} to keep going with the migration");
			}
			return 1;
		}

		// run the pre-migrate actions
		$this->preMigrateActions($input, $output, $params);

		// run the migration
		try {
			$this->migration->runMigration($params);
		} catch (MigrateException $e) {
			$output->writeln("<error>Something went wrong: {$e->getMessage()}</error>");
			return 1;
		}

		// save the state
		$this->migration->saveState();

		// run post-saved actions
		$this->postSavedActions($input, $output);

		$currentState = $this->migration->getState();
		if (\get_class($currentState) === StateFinish::class) {
			$output->writeln('Data migration has ended. There is nothing left to do.');
		} else {
			$output->writeln("Continue the migration with {$currentState->associatedCommand()}");
		}
		return 0;
	}
}
