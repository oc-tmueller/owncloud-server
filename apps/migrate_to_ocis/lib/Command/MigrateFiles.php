<?php
// SPDX-License-Identifier: Apache-2.0

namespace OCA\MigrateToInfiniteScale\Command;

use OCA\MigrateToInfiniteScale\MigrationState\Migration;
use OCA\MigrateToInfiniteScale\MigrationState\StateMigrateFiles;
use OCA\MigrateToInfiniteScale\MigrationState\State;
use OCA\MigrateToInfiniteScale\MigrationState\Exceptions\VerifyStateException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MigrateFiles extends CommandMigration {
	public function __construct(Migration $migration) {
		parent::__construct($migration);
	}

	protected function configure() {
		parent::configure();
		$this
			->setName('migrate:to-ocis:migrate:files')
			->setDescription('Migrates ownCloud files to the configured ocis instance. See also: https://doc.owncloud.com/server/latest/admin_manual/maintenance/migrating_to_ocis.html')
			->addArgument('ocis-admin', InputArgument::REQUIRED);
	}

	protected function prepareParams(InputInterface $input, OutputInterface $output): array {
		// For convenience, we'll ask for all the parameters during the preMigrateActions.
		// Just return an empty array here
		return [];
	}

	protected function verifyState(State $state, array &$params): ?string {
		if (\get_class($state) !== StateMigrateFiles::class) {
			throw new VerifyStateException('Wrong migration state to migrate the files.');
		}
		return null;
	}

	protected function preMigrateActions(InputInterface $input, OutputInterface $output, array &$params) {
		$user = $input->getArgument('ocis-admin');
		$password = $this->askAdminPassword($input, $output, $user);

		$params = [
			'adminUser' => $user,
			'adminPassword' => $password,
			'output' => $output,
		];

		$output->writeln("Migrating files ...");
	}

	protected function postSavedActions(InputInterface $input, OutputInterface $output) {
		// Nothing to do
	}
}
