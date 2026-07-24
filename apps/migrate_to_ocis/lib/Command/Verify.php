<?php
// SPDX-License-Identifier: Apache-2.0

namespace OCA\MigrateToInfiniteScale\Command;

use OCA\MigrateToInfiniteScale\Helper\Storage;
use OCA\MigrateToInfiniteScale\MigrationState\Migration;
use OCA\MigrateToInfiniteScale\MigrationState\StateVerify;
use OCA\MigrateToInfiniteScale\MigrationState\State;
use OCA\MigrateToInfiniteScale\MigrationState\Exceptions\VerifyStateException;
use OCP\Util;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Check if the local ownCloud Classic installation can be migrated.
 */
class Verify extends CommandMigration {
	/** @var Storage */
	private Storage $storage;

	public function __construct(Migration $migration, Storage $storage) {
		parent::__construct($migration);
		$this->storage = $storage;
	}

	protected function configure() {
		parent::configure();
		$this
			->setName('migrate:to-ocis:verify')
			->setDescription('Verifies the ownCloud instance to be ready for migration. See also: https://doc.owncloud.com/server/latest/admin_manual/maintenance/migrating_to_ocis.html');
	}

	protected function prepareParams(InputInterface $input, OutputInterface $output): array {
		// it just needs the output for the migration
		return [
			'output' => $output,
		];
	}

	protected function verifyState(State $state, array &$params): ?string {
		if (!($state instanceof StateVerify)) {
			throw new VerifyStateException('Wrong migration state to run the verification.');
		}
		return null;
	}

	protected function preMigrateActions(InputInterface $input, OutputInterface $output, array &$params) {
		$output->writeln("Verifying local users ...");
	}

	protected function postSavedActions(InputInterface $input, OutputInterface $output) {
		# display total storage
		$usedStorage = $this->storage->getUsedTotalSpace();
		$output->writeln('');
		$output->writeln("Total disk storage: " . Util::humanFileSize($usedStorage));
		$output->writeln('');
		$output->writeln("Congratulations - this instance is ready to be migrated to ownCloud InfiniteScale!");
	}
}
