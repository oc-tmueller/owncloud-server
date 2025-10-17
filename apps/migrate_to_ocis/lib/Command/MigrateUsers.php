<?php

namespace OCA\MigrateToInfiniteScale\Command;

use OCA\MigrateToInfiniteScale\OCIS\ClientService;
use OCA\MigrateToInfiniteScale\MigrationState\Migration;
use OCA\MigrateToInfiniteScale\MigrationState\StateMigrateUsers;
use OCA\MigrateToInfiniteScale\MigrationState\State;
use OCA\MigrateToInfiniteScale\MigrationState\VerifyStateException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MigrateUsers extends CommandMigration {
	/** @var Migration */
	//private Migration $migration;
	/** @var ClientService */
	private ClientService $ocisClientService;

	public function __construct(Migration $migration, ClientService $ocisClientService) {
		parent::__construct($migration);
		//$this->migration = $migration;
		$this->ocisClientService = $ocisClientService;
	}

	protected function configure() {
		$this
			->setName('migrate:to-ocis:migrate:users')
			->setDescription('Migrates ownCloud users to the configured ocis instance. See also: https://doc.owncloud.com/server/latest/admin_manual/maintenance/migrating_to_ocis.html')
			->addArgument('ocis-admin', InputArgument::REQUIRED);
	}

	protected function prepareParams(InputInterface $input, OutputInterface $output): array {
		// For convenience, we'll ask for all the parameters during the preMigrateActions.
		// Just return an empty array here
		return [];
	}

	protected function verifyState(State $state, array &$params): ?string {
		if (\get_class($state) !== StateMigrateUsers::class) {
			throw new VerifyStateException('Wrong migration state to migrate the users.');
		}
		return null;
	}

	protected function preMigrateActions(InputInterface $input, OutputInterface $output, array &$params) {
		$this->ocis_admin_user = $input->getArgument('ocis-admin');
		$this->askAdminPassword($input, $output);

		$client = $this->ocisClientService->newOCISClient();
		$token = $client->tokenExchange($this->ocis_admin_user, $this->ocis_admin_password, $this->ocis_admin_user);
		$apps = $client->getApplications($token);
		$chosenAppRole = $this->askForDefaultRole($input, $output, $apps);

		$params = [
			'roleId' => $chosenAppRole[1],
			'appId' => $chosenAppRole[0],
			'adminUser' => $this->ocis_admin_user,
			'adminPassword' => $this->ocis_admin_password,
			'output' => $output,
		];

		$output->writeln("Migrating users ...");
	}

	protected function postSavedActions(InputInterface $input, OutputInterface $output) {
		// Nothing to do
	}

	/*
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$this->migration->loadState();
		$currentState = $this->migration->getState();
		if (\get_class($currentState) !== StateMigrateUsers::class) {
			$output->writeln('Wrong migration state to migrate the users.');
			$output->writeln('Please run "' . $currentState->associatedCommand() . '" to keep going with the migration');
			return 1;
		}

		//$this->output = $output;
		//$code = $this->preExecute($input);
		//if ($code !== 0) {
		//	return $code;
		//}

		# get user access
		# ensure the ocis instance is reachable
		$this->ocis_admin_user = $input->getArgument('ocis-admin');
		$this->askAdminPassword($input, $output);
		//$token = $this->getAdminAccessToken();

		$client = $this->ocisClientService->newOCISClient();
		$token = $client->tokenExchange($this->ocis_admin_user, $this->ocis_admin_password, $this->ocis_admin_user);

		$apps = $client->getApplications($token);
		$chosenAppRole = $this->askForDefaultRole($input, $output, $apps);

		$params = [
			'roleId' => $chosenAppRole[1],
			'appId' => $chosenAppRole[0],
			'adminUser' => $this->ocis_admin_user,
			'adminPassword' => $this->ocis_admin_password,
			'output' => $output,
		];

		try {
			$output->writeln("Migrating users ...");
			$this->migration->runMigration($params);
		} catch (MigrateException $e) {
			$output->writeln("Something went wrong: {$e->getMessage()}");
			return 1;
		}

		$this->migration->saveState();
		return 0;
	}
	 */
}
