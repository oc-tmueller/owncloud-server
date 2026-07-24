<?php
// SPDX-License-Identifier: Apache-2.0

namespace OCA\MigrateToInfiniteScale\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use OCA\MigrateToInfiniteScale\MigrationState\Migration;
use OCA\MigrateToInfiniteScale\MigrationState\State;
use OCA\MigrateToInfiniteScale\MigrationState\StateFinish;
use OCA\MigrateToInfiniteScale\MigrationState\Exceptions\MigrateException;
use OCA\MigrateToInfiniteScale\MigrationState\Exceptions\UnskippableException;
use OCA\MigrateToInfiniteScale\MigrationState\Exceptions\VerifyStateException;

/**
 * The CommandMigration class will provide the same execute method for all
 * migration commands, and it can't be modified. This will ensure that
 * all the commands will behave the same way.
 *
 * The workflow is:
 * 1. prepare the parameters -> delegated to each command
 * 2. load the current migrate state -> handled by this class
 * 3. verify the current state is right for the command -> delegated to each command
 *    3.1. CommandMigration might switch to a different state based on verification result
 *    3.2. CommandMigration will show some message if the verification fails, and abort
 * 4. run some pre-migrate actions -> delegated to each command
 * 5. run the migration -> handled by this class
 * 6. save the migration state -> handled by this class
 * 7. run some post-save actions -> delegated to each command
 *
 * For the delegated actions, what is expected is:
 * - prepareParams -> read and gather any parameter that the migration will need. This is
 * important specially if the state verification need any of those parameters.
 * - verifyState -> verify that the provided state is the right one in order to run
 * the command. The parameters returned in the prepareParams step will be provided.
 * Returning a state to make the CommandMigration to switch to it is possible,
 * but shouldn't be needed (only on special scenarios).
 * - preMigrateActions -> run some actions before the migration. The common use case
 * is to ask for things (passwords) interactively. This happens after the verification,
 * so we don't need to ask for anything if we're in the wrong state. Parameters
 * can be added and modified in this step.
 * - postSavedActions -> run some actions after the new state has been saved. This
 * usually just includes writing some messages, although you can show some reports. Note
 * that the new state has been already saved and you (usually) won't be able to re-run
 * the same command in case something fails here.
 *
 * Note that the CommandMigration will take care of all the handling of the Migration,
 * in particular, each migration command SHOULD NOT KEEP a reference to the migration
 * object, just provide it to the CommandMigration.
 */
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
	 * Configure the "skip" option for all the commands.
	 * Subclass should call this method (via "parent::configure()") and
	 * then configure whatever the actual command needs.
	 */
	protected function configure() {
		$this->addOption('skip', null, null, 'skip this command and move to the next one');
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
	 *
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 */
	abstract protected function postSavedActions(InputInterface $input, OutputInterface $output);

	/**
	 * Run the migration as explained in the class documentation.
	 * The "final" keyword is intentional since we want all the migration commands
	 * to behave the same way. Custom behavior is allowed via the abstract methods
	 * that need to be implemented.
	 *
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 * @return int
	 */
	final protected function execute(InputInterface $input, OutputInterface $output): int {
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
			$this->printException($output, $ex);

			// if the state is the last one (StateFinish) show a different message
			if ($this->migration->getState() instanceof StateFinish) {
				$output->writeln('Data migration has ended. There is nothing left to do.');
			} else {
				$output->writeln("Consider to run {$currentState->associatedCommand()} to keep going with the migration");
			}
			return 1;
		}

		// check what action to run
		$action = 'runMigration';
		if ($input->getOption('skip')) {
			$action = 'runSkip';
		}

		// run the action
		$code = $this->$action($input, $output, $params);
		if ($code !== 0) {
			// If code isn't 0 then the action failed.
			// Abort immediately without saving the state
			return $code;
		}

		// save the state
		$this->migration->saveState();

		// run post-saved actions
		$this->postSavedActions($input, $output);

		$currentState = $this->migration->getState();
		if ($currentState instanceof StateFinish) {
			$output->writeln('Data migration has ended. There is nothing left to do.');
		} else {
			$output->writeln("Continue the migration with {$currentState->associatedCommand()}");
		}
		return 0;
	}

	private function runMigration(InputInterface $input, OutputInterface $output, array $params): int {
		// run the pre-migrate actions
		try {
			$this->preMigrateActions($input, $output, $params);
		} catch (\Exception $ex) {
			// no expectation on the exceptions thrown by the
			// preMigrateActions method, so catch all for now
			$this->printException($output, $ex);
			return 1;
		}

		// run the migration
		try {
			$this->migration->runMigration($params);
		} catch (MigrateException $ex) {
			$this->printException($output, $ex);
			return 1;
		}

		return 0;
	}

	private function runSkip(InputInterface $input, OutputInterface $output, array $params): int {
		try {
			$this->migration->runSkip($params);
			return 0;
		} catch (UnskippableException $ex) {
			$message = "This command cannot be skipped";
			if ($ex->getMessage() !== '') {
				$message .= ": {$ex->getMessage()}";
			}
			$output->writeln("<error>{$message}</error>");
		}
		return 1;
	}

	private function printException(OutputInterface $output, \Exception $ex) {
		$formatter = $this->getHelper('formatter');

		$messages = [$ex->getMessage()];
		$previous = $ex->getPrevious();
		while ($previous !== null) {
			$messages[] = "Caused by: {$previous->getMessage()}";
			$previous = $previous->getPrevious();
		}

		$formattedBlock = $formatter->formatBlock($messages, 'error', true);
		$output->writeln($formattedBlock);
	}
}
