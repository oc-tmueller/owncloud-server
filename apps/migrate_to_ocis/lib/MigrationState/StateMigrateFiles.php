<?php

namespace OCA\MigrateToInfiniteScale\MigrationState;

use OC\Authentication\Token\DefaultTokenProvider;
use OCA\MigrateToInfiniteScale\ConflictLog\LogFile;
use OCA\MigrateToInfiniteScale\ConflictLog\LogService;
use OCA\MigrateToInfiniteScale\Helper\ProcessOutputLineProcessor;
use OCA\MigrateToInfiniteScale\Helper\UserHandler;
use OCA\MigrateToInfiniteScale\MigrationState\StateMigrateShares;
use OCA\MigrateToInfiniteScale\MigrationState\Exceptions\MigrateException;
use OCA\MigrateToInfiniteScale\MigrationState\Exceptions\UnskippableException;
use OCA\MigrateToInfiniteScale\OCIS\ClientException;
use OCA\MigrateToInfiniteScale\OCIS\ClientService;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\IUser;
use OCP\IURLGenerator;
use OCP\AppFramework\Utility\ITimeFactory;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use Ramsey\Uuid\Uuid;

class StateMigrateFiles implements State {
	private static string $rclone_bin = __DIR__ . "/../../bin/rclone_linux_amd64";

	/** @var ClientService */
	private ClientService $ocisClientService;
	/** @var UserHandler */
	private UserHandler $userHandler;
	/** @var IUserManager */
	private IUserManager $userManager;
	/** @var IConfig */
	private IConfig $config;
	/** @var LogService */
	private LogService $logService;
	/** @var DefaultTokenProvider */
	private DefaultTokenProvider $tokenProvider;
	/** @var IURLGenerator */
	private IURLGenerator $generator;
	/** @var ITimeFactory */
	private ITimeFactory $timeFactory;

	public function __construct(
		ClientService $ocisClientService,
		UserHandler $userHandler,
		IUserManager $userManager,
		IConfig $config,
		LogService $logService,
		DefaultTokenProvider $tokenProvider,
		IURLGenerator $generator,
		ITimeFactory $timeFactory
	) {
		$this->ocisClientService = $ocisClientService;
		$this->userHandler = $userHandler;
		$this->userManager = $userManager;
		$this->config = $config;
		$this->logService = $logService;
		$this->tokenProvider = $tokenProvider;
		$this->generator = $generator;
		$this->timeFactory = $timeFactory;
	}

	/**
	 * Migrate the files of all users from OC10 to oCIS using the rclone binary
	 *
	 * Required params:
	 * - 'adminUser' -> the oCIS' admin username
	 * - 'adminPassword' -> the oCIS' admin password
	 * - 'output' -> a Symfony's OutputInterface to write messages
	 *
	 * Move to StateMigrateShares on success.
	 *
	 * @throws MigrateException
	 */
	public function migrate(array $params, Migration $migration) {
		try {
			$this->doMigrate($params, $migration);
		} catch (ClientException $ex) {
			// there is a token exchange that could throw a ClientException
			throw new MigrateException("Migrating files failed", 0, $ex);
		}
	}

	private function doMigrate(array $params, Migration $migration) {
		$now = $this->timeFactory->getTime();
		$logFile = $this->logService->newLogFile();
		if (!$logFile->open("migrate-ocis-$now.csv")) {
			throw new MigrateException("Failed to create conflict file: migrate-ocis-$now.csv");
		}

		// include an ocisClient in the parameters to avoid creating a new one for each user
		$params['client'] = $this->ocisClientService->newOCISClient();
		// ocisHost and insecure flag are needed because files will be moved through rclone, not through the ocis client
		$params['ocisHost'] = $this->config->getAppValue('migrate_to_ocis', 'ocis_host', null);
		$params['insecure'] = $this->config->getAppValue('migrate_to_ocis', 'ocis_host_insecure', false);

		if ($params['ocisHost'] === null) {
			// This is weird and it shouldn't happen. We should
			// consider to move to the initial state and start over
			throw new MigrateException("ocis host isn't defined!");
		}

		$ok = true;
		$this->userManager->callForUsers(function (IUser $user) use (&$ok, $logFile, $params) {
			$output = $params['output'];
			'@phan-var OutputInterface $output'; // @phpstan-ignore-line
			$output->writeln(" " . $user->getUserName() . "/" . $user->getEMailAddress());

			if ($user->getLastLogin() === 0) {
				$output->writeln("  User hasn't logged in. Skipping");
				return;
			}

			if ($this->userHandler->hasBeenMigrated($params['adminUser'], $params['adminPassword'], $user)) {
				if (!$this->cloneFilesForUser($user, $logFile, $params)) {
					$ok = false;
				}
			} else {
				$output->writeln("  <error>User not found in oCIS. Skipping file migration for this user</error>");
			}
		});

		if (!$ok) {
			throw new MigrateException("Issues did arise when migrating files and folders...Please review {$logFile->getName()} and fix any issues which have been reported.");
		}

		$migration->switchState(StateMigrateShares::class);
	}

	public function skip(array $params, Migration $migration) {
		throw new UnskippableException();
	}

	public function associatedCommand(): string {
		return 'migrate:to-ocis:migrate:files';
	}

	private function cloneFilesForUser(IUser $user, LogFile $conflictLogFile, array $params): bool {
		$email = $user->getEMailAddress();
		if ($email === null) {
			return false;
		}

		$output = $params['output'];
		$user_token = $params['client']->tokenExchange($params['adminUser'], $params['adminPassword'], $user->getUserName());
		$password = $this->generateAppPassword($user);
		try {
			$ocis_connection = $this->buildRCloneConnectionStringForOCIS($params['ocisHost'], $user->getUserName(), $user_token);
			$oc10_connection = $this->buildRCloneConnectionStringForOC($user, $password);
			$output->writeln("ocis connect: $ocis_connection", OutputInterface::VERBOSITY_VERBOSE);
			$output->writeln("oc10 connect: $oc10_connection", OutputInterface::VERBOSITY_VERBOSE);

			$cmd = [
				self::$rclone_bin,
				'sync',
				$params['insecure'] ? '--no-check-certificate' : '',
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
			// TODO: ProcessOutputLineProcessor should be injected
			$lp = new ProcessOutputLineProcessor(function ($type, $line) use (&$verified, $user, $conflictLogFile, $params) {
				$logLevels = ['EMERGENCY', 'ALERT', 'CRITICAL', 'ERROR', 'WARNING', 'NOTICE'];
				foreach ($logLevels as $logLevel) {
					if (str_contains($line, $logLevel)) {
						$conflictLogFile->putCSV([$logLevel, $user->getUserName(), $user->getEMailAddress(), $line]);
						# notice on config file access is not blocking the migration process
						if (!(str_contains($line, 'NOTICE: Config file') && str_contains($line, 'not found - using defaults'))) {
							$verified = false;
						}
					}
					// TODO: Need to consider the following line because it should trigger an error
					// 2025/10/16 12:00:35 Failed to create file system for "ocis-migrate,type=webdav,url=\"https:///remote.php/webdav/\",vendor=owncloud,user=\"user6\",pass=\"CIZZBxAe2MIjYglQbmBNixEidpwqRnjNEdop24WZGiE\":/ownCloud": read metadata failed: Propfind "https:///remote.php/webdav/ownCloud": http: no Host in request URL
				}
				$params['output']->writeln($line);
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

	private function generateAppPassword(IUser $user): string {
		$token = Uuid::uuid4()->toString();
		$uid = $user->getUID();

		$this->tokenProvider->generateToken($token, $uid, $uid, null, 'ocis migration');
		return $token;
	}

	private function buildRCloneConnectionStringForOCIS(string $ocis_host, string $userId, string $password): string {
		$url = "https://$ocis_host/remote.php/webdav/";
		$obscured_password = self::RCloneObscure($password);
		$rclone_connection_elements = [
			"ocis-migrate",
			'type=webdav',
			"url=\"$url\"",
			'vendor=owncloud',
			"user=\"$userId\"",
			"pass=\"$obscured_password\"",
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

	public static function RCloneObscure(string $x): string {
		return exec(self::$rclone_bin . ' obscure --config= ' . escapeshellarg($x));
	}
}
