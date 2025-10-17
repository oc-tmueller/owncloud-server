<?php

namespace OCA\MigrateToInfiniteScale\Command;

use OCA\MigrateToInfiniteScale\OCIS\ClientService;
use OCA\MigrateToInfiniteScale\MigrationState\Migration;
use OCA\MigrateToInfiniteScale\MigrationState\StateMigrateShares;
use OCA\MigrateToInfiniteScale\MigrationState\State;
use OCA\MigrateToInfiniteScale\MigrationState\VerifyStateException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MigrateShares extends CommandMigration {
	public function __construct(Migration $migration) {
		parent::__construct($migration);
	}

	protected function configure() {
		$this
			->setName('migrate:to-ocis:migrate:shares')
			->setDescription('Migrates ownCloud shares to the configured ocis instance. See also: https://doc.owncloud.com/server/latest/admin_manual/maintenance/migrating_to_ocis.html')
			->addArgument('ocis-admin', InputArgument::REQUIRED);
	}

	protected function prepareParams(InputInterface $input, OutputInterface $output): array {
		// For convenience, we'll ask for all the parameters during the preMigrateActions.
		// Just return an empty array here
		return [];
	}

	protected function verifyState(State $state, array &$params): ?string {
		if (\get_class($state) !== StateMigrateShares::class) {
			throw new VerifyStateException('Wrong migration state to migrate the files.');
		}
		return null;
	}

	protected function preMigrateActions(InputInterface $input, OutputInterface $output, array &$params) {
		$this->ocis_admin_user = $input->getArgument('ocis-admin');
		$this->askAdminPassword($input, $output);

		$params = [
			'adminUser' => $this->ocis_admin_user,
			'adminPassword' => $this->ocis_admin_password,
			'output' => $output,
		];

		$output->writeln("Migrating shares ...");
	}

	protected function postSavedActions(InputInterface $input, OutputInterface $output) {
		// Nothing to do
	}
}
