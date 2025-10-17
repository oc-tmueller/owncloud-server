<?php

namespace OCA\MigrateToInfiniteScale\Command;

use JsonException;
use OCA\MigrateToInfiniteScale\Helper\EMailAddress;
use OCA\MigrateToInfiniteScale\Helper\Storage;
use OCA\MigrateToInfiniteScale\MigrationState\Migration;
use OCA\MigrateToInfiniteScale\MigrationState\StateVerify;
use OCA\MigrateToInfiniteScale\MigrationState\State;
use OCA\MigrateToInfiniteScale\MigrationState\VerifyStateException;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Util;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Verify extends CommandMigration {
	/** @var Migration */
	//private Migration $migration;

	public function __construct(Migration $migration) {
		parent::__construct($migration);
		//$this->migration = $migration;
	}

	protected function configure() {
		parent::configure();
		$this
			->setName('migrate:to-ocis:verify')
			->setDescription('Verifies the ownCloud instance to be ready for migration. See also: https://doc.owncloud.com/server/latest/admin_manual/maintenance/migrating_to_ocis.html')
			->addArgument('ocis-admin', InputArgument::REQUIRED);
	}

	protected function prepareParams(InputInterface $input, OutputInterface $output): array {
		// it just needs the output for the migration
		return [
			'output' => $output,
		];
	}

	protected function verifyState(State $state, array &$params): ?string {
		if (\get_class($state) !== StateVerify::class) {
			throw new VerifyStateException('Wrong migration state to run the verification.');
		}
		return null;
	}

	protected function preMigrateActions(InputInterface $input, OutputInterface $output, array &$params) {
		$output->writeln("Verifying local users ...");
	}

	protected function postSavedActions(InputInterface $input, OutputInterface $output) {
		# display total storage
		$storage = new Storage(\OC::$server->getDatabaseConnection());
		$usedStorage = $storage->getUsedTotalSpace();
		$output->writeln('');
		$output->writeln("Total disk storage: " . Util::humanFileSize($usedStorage));
		$output->writeln('');
		$output->writeln("Congratulations - this instance is ready to be migrated to ownCloud InfiniteScale!");
	}

	/**
	 * @throws JsonException
	 */
	/*
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$this->migration->loadState();
		$currentState = $this->migration->getState();
		if (\get_class($currentState) !== StateVerify::class) {
			$output->writeln('Wrong migration state to run the verification.');
			$output->writeln('Please run "' . $currentState->associatedCommand() . '" to keep going with the migration');
			return 1;
		}

		$params = [
			'output' => $output,
		];

		try {
			$output->writeln("Verifying local users ...");
			$this->migration->runMigration($params);
		} catch (MigrateException $e) {
			$output->writeln("Something went wrong: {$e->getMessage()}");
			$output->writeln("<error>Please make sure all users meet the requirements.</error>");
			$output->writeln("<error>This instance is NOT ready to be migrated to OCIS!</error>");
			return 1;
		}

		$this->migration->saveState();
		$currentState = $this->migration->getState();

		# display total storage
		$storage = new Storage(\OC::$server->getDatabaseConnection());
		$usedStorage = $storage->getUsedTotalSpace();
		$output->writeln('');
		$output->writeln("Total disk storage: " . Util::humanFileSize($usedStorage));
		$output->writeln('');

		$output->writeln("Congratulations - this instance is ready to be migrated to ownCloud InfiniteScale!");
		$output->writeln('Continue the migration with ' . $currentState->associatedCommand());
		return 0;
	}
	 */
}
