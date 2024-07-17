<?php
namespace OCA\MigrateToInfiniteScale\Command;

use JsonException;
use OC\Authentication\Token\DefaultTokenProvider;
use OC\Files\Filesystem;
use OC\Files\ObjectStore\ObjectStoreStorage;
use OCA\MigrateToInfiniteScale\Helper\ConflictLogFile;
use OCA\MigrateToInfiniteScale\Helper\OCISClient;
use OCA\MigrateToInfiniteScale\Helper\ProcessOutputLineProcessor;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUser;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Process\Process;

trait CommandTrait {
	use OutputAware;

	private static string $rclone_bin = __DIR__ . "/../../bin/rclone_linux_amd64";

	private IConfig $config;
	private IURLGenerator $generator;
	private DefaultTokenProvider $tokenProvider;
	private string $ocis_host;
	private string $shared_migration_api_key;
	private bool $insecure = false;
	private string $ocis_admin;

	public function preExecute(InputInterface $input): int {
		/*
		if (\OC::$server->getDatabaseConnection()->getDatabasePlatform() instanceof SqlitePlatform) {
			$this->writeln('<error>Sqlite based systems cannot be migrated.</error>');
			return 1;
		}
		*/
		$storage = Filesystem::getStorage('/');
		if ($storage->instanceOfStorage(ObjectStoreStorage::class)) {
			$this->writeln('<error>S3 primary object storage cannot be migrated for the time being.</error>');
			return 1;
		}

		$this->insecure = $input->getOption('insecure');
		# get service id and other configs
		if (!$this->setupDone()) {
			$this->writeln('<error>Please run migrate:to-ocis:init first</error>');
			return 1;
		}

		return 0;
	}

	/**
	 * @throws JsonException
	 */
	private function getAdminAccessToken(): string {
		# TODO: take care of renewal
		# TODO: no need to get a new token every time
		return $this->actAsUser($this->ocis_admin);
	}

	/**
	 * @throws JsonException
	 */
	private function actAsUser(string $email_address): string {
		$client = \OC::$server->getHTTPClientService()->newClient();
		$client = new OCISClient($client, $this->ocis_host, $this->insecure);
		return $client->tokenExchange($this->shared_migration_api_key, $email_address);
	}

	/**
	 * @throws JsonException
	 */
	private function setupDone(): bool {
		$ocis_host = $this->config->getAppValue('migrate_to_ocis', 'ocis_host', null);
		$shared_migration_api_key = $this->config->getAppValue('migrate_to_ocis', 'shared_migration_api_key', null);
		if ($shared_migration_api_key === null) {
			return false;
		}

		$this->ocis_host = $ocis_host;
		$this->shared_migration_api_key = $shared_migration_api_key;

		return true;
	}

	private function cloneFilesForUser(IUser $user, ConflictLogFile $conflictLogFile): bool {
		$email = $user->getEMailAddress();
		if ($email === null) {
			return false;
		}

		$user_token = $this->actAsUser($user->getEMailAddress());
		$password = $this->generateAppPassword($user);
		try {
			$ocis_connection = $this->buildRCloneConnectionStringForOCIS($user_token);
			$oc10_connection = $this->buildRCloneConnectionStringForOC($user, $password);
			$this->writeln("ocis connect: $ocis_connection", true);
			$this->writeln("oc10 connect: $oc10_connection", true);

			$cmd = [
				self::$rclone_bin,
				'sync',
				$this->insecure ? '--no-check-certificate' : '',
				'--create-empty-src-dirs',
				'--ignore-case',
				'--ignore-case-sync',
				'--webdav-owncloud-exclude-shares=true',
				'--webdav-owncloud-exclude-mounts=true',
				'--config=',
				'-v',
				"$oc10_connection:/",
				"$ocis_connection:/ownCloud",
			];
			$verified = true;
			$lp = new ProcessOutputLineProcessor(function ($type, $line) use (&$verified, $user, $conflictLogFile) {
				$logLevels = ['EMERGENCY', 'ALERT', 'CRITICAL', 'ERROR', 'WARNING', 'NOTICE'];
				foreach ($logLevels as $logLevel) {
					if (str_contains($line, $logLevel)) {
						$conflictLogFile->putCSV([$logLevel, $user->getUID(), $user->getEMailAddress(), $line]);
						# notice on config file access is not blocking the migration process
						if (!(str_contains($line, 'NOTICE: Config file') && str_contains($line, 'not found - using defaults'))) {
							$verified = false;
						}
					}
				}
				echo $line . PHP_EOL;
			});
			$process = new Process($cmd);
			$process->setTimeout(null);
			$process->run($lp);

			# close the output processor
			$lp->close();

			return $verified;
		} finally {
			# cleanup app password
			$this->tokenProvider->invalidateToken($password);
		}
	}

	private function buildRCloneConnectionStringForOCIS(string $user_token): string {
		$url = "https://$this->ocis_host/remote.php/webdav/";
		$rclone_connection_elements = [
			"ocis-migrate",
			'type=webdav',
			"url=\"$url\"",
			'vendor=owncloud',
			"bearer_token=\"$user_token\"",
		];

		return implode(',', $rclone_connection_elements);
	}

	private function buildRCloneConnectionStringForOC(IUser $user, string $password): string {
		$userId = $user->getUID();
		$url = $this->generator->getAbsoluteUrl($this->generator->linkTo('', 'remote.php') . '/dav/files/' . rawurlencode($userId) . '/');
		$obscured_password = self::RCloneObscure($password);
		$connection_elements = [
			'oc-demo',
			'type=webdav',
			"url=\"$url\"",
			'vendor=owncloud',
			"user=\"$userId\"",
			"pass=\"$obscured_password\"",
		];
		return implode(',', $connection_elements);
	}

	private function generateAppPassword(IUser $user): string {
		$token = Uuid::uuid4()->toString();
		$uid = $user->getUID();

		$this->tokenProvider->generateToken($token, $uid, $uid, null, 'ocis migration');
		return $token;
	}

	public static function RCloneObscure(string $x): string {
		return exec(self::$rclone_bin . ' obscure --config= ' . escapeshellarg($x));
	}
}
