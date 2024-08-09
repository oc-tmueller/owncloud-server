<?php

namespace OCA\MigrateToInfiniteScale\Command;

use OCP\IConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Init extends Command {
	private IConfig $config;
	private bool $force;

	public function __construct(IConfig $config) {
		parent::__construct();
		$this->config = $config;
	}

	protected function configure() {
		$this
			->setName('migrate:to-ocis:init')
			->setDescription('Initialize the migration process. See also: https://doc.owncloud.com/server/latest/admin_manual/maintenance/migrating_to_ocis.html')
			->addArgument('ocis_host', InputArgument::REQUIRED)
			->addOption('force', 'f')
		;
	}

	/**
	 * @throws \JsonException
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$this->force = $input->getOption('force');

		# setup ocis_host
		$new_ocis_host = $input->getArgument('ocis_host');
		$this->saveSetting('ocis_host', $new_ocis_host);

		$output->writeln("Migration initialized!");
		$output->writeln('');
		$output->writeln('Continue the migration with ./occ migrate:to-ocis:verify');

		return 0;
	}

	private function saveSetting(string $key, $value): void {
		if (!$this->force) {
			$existing_value = $this->config->getAppValue('migrate_to_ocis', $key, null);
			if ($existing_value !== null) {
				throw new \InvalidArgumentException("Value '$key' already set up.");
			}
		}
		if (\is_array($value)) {
			$value = json_encode($value, JSON_THROW_ON_ERROR);
		}

		$this->config->setAppValue('migrate_to_ocis', $key, $value);
	}
}
