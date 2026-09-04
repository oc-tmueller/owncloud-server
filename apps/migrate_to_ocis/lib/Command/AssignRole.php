<?php
// SPDX-License-Identifier: Apache-2.0

namespace OCA\MigrateToInfiniteScale\Command;

use OCA\MigrateToInfiniteScale\OCIS\ClientService;
use OCA\MigrateToInfiniteScale\MigrationState\Migration;
use OCA\MigrateToInfiniteScale\MigrationState\StateAssignRole;
use OCA\MigrateToInfiniteScale\MigrationState\State;
use OCA\MigrateToInfiniteScale\MigrationState\Exceptions\VerifyStateException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AssignRole extends CommandMigration {
	/** @var ClientService */
	private ClientService $ocisClientService;

	public function __construct(Migration $migration, ClientService $ocisClientService) {
		parent::__construct($migration);
		$this->ocisClientService = $ocisClientService;
	}

	protected function configure() {
		parent::configure();
		$this
			->setName('migrate:to-ocis:assign-role')
			->setDescription('Assign the chosen role to all the users in the configured ocis instance. See also: https://doc.owncloud.com/server/latest/admin_manual/maintenance/migrating_to_ocis.html')
			->addArgument('ocis-admin', InputArgument::REQUIRED);
	}

	protected function prepareParams(InputInterface $input, OutputInterface $output): array {
		// For convenience, we'll ask for all the parameters during the preMigrateActions.
		// Just return an empty array here
		return [];
	}

	protected function verifyState(State $state, array &$params): ?string {
		if (!($state instanceof StateAssignRole)) {
			throw new VerifyStateException('Wrong migration state to migrate the users.');
		}
		return null;
	}

	protected function preMigrateActions(InputInterface $input, OutputInterface $output, array &$params) {
		$user = $input->getArgument('ocis-admin');
		$password = $this->askAdminPassword($input, $output, $user);

		$client = $this->ocisClientService->newOCISClient();
		$token = $client->tokenExchange($user, $password, $user);
		$apps = $client->getApplications($user, $token);
		$chosenAppRole = $this->askForDefaultRole($input, $output, $apps);

		$params = [
			'roleId' => $chosenAppRole[1],
			'appId' => $chosenAppRole[0],
			'adminUser' => $user,
			'adminPassword' => $password,
			'output' => $output,
		];

		$output->writeln("Assigning role ...");
	}

	protected function postSavedActions(InputInterface $input, OutputInterface $output) {
		// Nothing to do
	}
}
