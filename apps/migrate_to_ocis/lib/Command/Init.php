<?php

namespace OCA\MigrateToInfiniteScale\Command;

use OCA\MigrateToInfiniteScale\MigrationState\Migration;
use OCA\MigrateToInfiniteScale\MigrationState\MigrateException;
use OCA\MigrateToInfiniteScale\MigrationState\StateInit;
use OCA\MigrateToInfiniteScale\MigrationState\State;
use OCA\MigrateToInfiniteScale\MigrationState\VerifyStateException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Init extends CommandMigration {
	//private Migration $migration;

	/**
	 * @param Migration $migration
	 */
	public function __construct(Migration $migration) {
		parent::__construct($migration);
		//$this->migration = $migration;
	}

	protected function configure() {
		$this
			->setName('migrate:to-ocis:init')
			->setDescription('Initialize the migration process. See also: https://doc.owncloud.com/server/latest/admin_manual/maintenance/migrating_to_ocis.html')
			->addArgument('ocis_host', InputArgument::REQUIRED)
			->addOption('force', 'f')
			->addOption('insecure', 'k')
		;
	}

	protected function prepareParams(InputInterface $input, OutputInterface $output): array {
		$force = $input->getOption('force');
		$insecure = $input->getOption('insecure');
		$new_ocis_host = $input->getArgument('ocis_host');

		return [
			'force' => $force,
			'value' => $new_ocis_host,
			'insecure' => $insecure,
		];
	}

	protected function verifyState(State $state, array &$params): ?string {
		if (\get_class($state) !== StateInit::class) {
			// We aren't in a starting state. We should abort.
			// We only keep going and re-init the migration if forced
			if (!$params['force']) {
				throw new VerifyStateException('The current state of the migration doesn\'t allow re-initialization');
			} else {
				// switch to the initial state
				return StateInit::class;
			}
		}
		return null;
	}

	protected function preMigrateActions(InputInterface $input, OutputInterface $output, array &$params) {
		// Nothing to do
	}

	protected function postSavedActions(InputInterface $input, OutputInterface $output) {
		$output->writeln("Migration initialized!");
	}

	/**
	 * @throws \JsonException
	 */
	/*
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$force = $input->getOption('force');
		$insecure = $insecure->getOption('insecure');
		$new_ocis_host = $input->getArgument('ocis_host');

		$this->migration->loadState();

		$currentState = $this->migration->getState();
		if (\get_class($currentState) !== StateInit::class) {
			// We aren't in a starting state. We should abort.
			// We only keep going and re-init the migration if forced
			if (!$force) {
				$output->writeln('The current state of the migration doesn\'t allow re-initialization');
				$output->writeln('Please run "' . $currentState->associatedCommand() . '" to keep going with the migration');
				return 1;
			} else {
				// switch to the initial state
				$this->migration->switchState(StateInit::class);
			}
		}

		$params = [
			'force' => $force,
			'value' => $new_ocis_host,
			'insecure' => $insecure,
		];

		try {
			$output->writeln("Initializing migration...\n");
			$this->migration->runMigration($params);
		} catch (MigrateException $e) {
			$output->writeln("<error>Something went wrong: {$e->getMessage()}</error>");
			if (($advice = $e->getAdvice()) !== '') {
				$output->writeln("<info>{$advice}</info>");
			}
			return 1;
		}

		$this->migration->saveState();
		$currentState = $this->migration->getState();

		$output->writeln("Migration initialized!");
		$output->writeln('');
		$output->writeln('Continue the migration with ' . $currentState->associatedCommand());

		return 0;
	}
	 */
}
