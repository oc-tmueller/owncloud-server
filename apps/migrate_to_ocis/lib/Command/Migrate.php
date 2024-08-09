<?php

namespace OCA\MigrateToInfiniteScale\Command;

use JsonException;
use OC\Authentication\Token\DefaultTokenProvider;
use OCA\MigrateToInfiniteScale\Helper\ConflictLogFile;
use OCA\MigrateToInfiniteScale\Helper\OCISClient;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Migrate extends CommandBase {
	private IUserManager $userManager;
	private ConflictLogFile $conflict_log_file;

	public function __construct(
		IConfig $config,
		IUserManager $userManager,
		IURLGenerator $generator,
		DefaultTokenProvider $tokenProvider
	) {
		parent::__construct();
		$this->config = $config;
		$this->userManager = $userManager;
		$this->generator = $generator;
		$this->tokenProvider = $tokenProvider;
	}

	protected function configure() {
		$this
			->setName('migrate:to-ocis')
			->setDescription('Migrates ownCloud to the configured ocis instance. See also: https://doc.owncloud.com/server/latest/admin_manual/maintenance/migrating_to_ocis.html')
			->addArgument('ocis-admin', InputArgument::REQUIRED)
			->addOption('insecure', 'k');
	}

	/**
	 * @throws JsonException
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$this->output = $output;
		$code = $this->preExecute($input);
		if ($code !== 0) {
			return $code;
		}
		# ensure verify command has been executed
		$instance_verified = $this->config->getAppValue('migrate_to_ocis', 'instance_verified', 'no');
		if ($instance_verified !== 'yes') {
			$this->writeln('<error>Please run migrate:to-ocis:verify first</error>');
			return 1;
		}

		# get user access
		# ensure the ocis instance is reachable
		$this->ocis_admin_user = $input->getArgument('ocis-admin');
		$this->askAdminPassword($input, $output);
		$this->getAdminAccessToken();

		$now = \time();
		$this->conflict_log_file = new ConflictLogFile();
		if (!$this->conflict_log_file->open("migrate-ocis-$now.csv")) {
			$this->writeln("Failed to create conflict file: migrate-ocis-$now.csv");
			return 1;
		}

		# first we create users in ocis
		$this->writeln("Migrating users ...");
		$this->migrateUsers();

		# copy files over to ocis
		$this->writeln("Migrating files ...");
		$ok = $this->cloneFiles();
		if (!$ok) {
			$this->writeln('<error>Issues did arise when migrating files and folders..</error>');
			$this->writeln("<error>Please review {$this->conflict_log_file->getName()} and fix any issues which have been reported.</error>");
			$this->writeln('');
			$this->writeln("Once resolved please re-run the migration process again.</error>");
			$this->writeln('');
			$this->writeln("Migration will stop here now until no more conflicts exist.</error>");
			return 1;
		}

		# migrate shares
		$this->writeln("Migrating shares ...");
		$this->migrateShares();

		return 0;
	}

	private function shallMigrate(IUser $user): bool {
		$userId = $user->getUID();
		if ($user->getEMailAddress() === null) {
			$this->writeln("<error>No Email for user $userId - it cannot be migrated to ownCloud InfiniteScale!</error>");
			return false;
		}
		if (!$user->isEnabled()) {
			$this->writeln("<warn>Disabled user $userId - it cannot be migrated to ownCloud InfiniteScale!</warn>");
			return false;
		}
		return true;
	}

	private function migrateUsers(): void {
		$this->userManager->callForUsers(function (IUser $user) {
			if ($this->shallMigrate($user)) {
				$this->writeln(" " . $user->getUserName() . "/" . $user->getEMailAddress());
				$this->migrateUser($user);
			}
		});
	}

	private function cloneFiles(): bool {
		$ok = true;
		$this->userManager->callForUsers(function (IUser $user) use (&$ok) {
			if ($this->shallMigrate($user)) {
				$this->writeln(" " . $user->getUserName() . "/" . $user->getEMailAddress());
				if (!$this->cloneFilesForUser($user, $this->conflict_log_file)) {
					$ok = false;
				}
			}
		});
		return $ok;
	}

	/**
	 * @throws JsonException
	 */
	private function migrateUser(IUser $user): void {
		$email = $user->getEMailAddress();
		$token = $this->getAdminAccessToken();

		$client = $this->initGraphApi();
		$created = $client->createUser($token, $user);
		if ($created) {
			$this->writeln("$email - user created in ownCloud InfiniteScale.");
		} else {
			$this->writeln("$email - user already existing in ownCloud InfiniteScale.");
		}
	}

	private function migrateShares(): void {
		$this->userManager->callForUsers(function (IUser $user) {
			if ($this->shallMigrate($user)) {
				$this->writeln(" " . $user->getUserName() . "/" . $user->getEMailAddress());
				$this->writeln("Shares are not yet being migrated!");
			}
		});
	}

	private function initGraphApi(): OCISClient {
		$client = \OC::$server->getHTTPClientService()->newClient();
		return new OCISClient($client, $this->ocis_host, $this->insecure);
	}
}
