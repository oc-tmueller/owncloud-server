<?php

namespace OCA\MigrateToInfiniteScale\Command;

use JsonException;
use OC\Authentication\Token\DefaultTokenProvider;
use OCA\MigrateToInfiniteScale\Helper\EMailAddress;
use OCA\MigrateToInfiniteScale\Helper\Storage;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Util;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Verify extends Command {
	use CommandTrait;

	private IUserManager $userManager;

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
			->setName('migrate:to-ocis:verify')
			->setDescription('Verifies the ownCloud instance to be ready for migration. See also: https://doc.owncloud.com/server/latest/admin_manual/maintenance/migrating_to_ocis.html')
			->addArgument('ocis-admin', InputArgument::REQUIRED)
			->addOption('insecure', 'k');
	}

	/**
	 * @throws JsonException
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$this->config->setAppValue('migrate_to_ocis', 'instance_verified', 'no');

		$this->output = $output;
		$code = $this->preExecute($input);
		if ($code !== 0) {
			return $code;
		}

		# get user access
		$this->ocis_admin = $input->getArgument('ocis-admin');
		$this->getAdminAccessToken();

		# first we verify users
		$this->writeln("Verifying users ...");
		$ok = $this->verifyUsers();
		if (!$ok) {
			$this->writeln("<error>Please make sure all users meet the requirements.</error>");
			$this->writeln("<error>This instance is NOT ready to be migrated to OCIS!</error>");
			return 1;
		}

		# display total storage
		$storage = new Storage(\OC::$server->getDatabaseConnection());
		$usedStorage = $storage->getUsedTotalSpace();
		$this->writeln();
		$this->writeln("Total disk storage: " . Util::humanFileSize($usedStorage));
		$this->writeln();

		$this->writeln("Congratulations - this instance is ready to be migrated to ownCloud InfiniteScale!");
		$this->config->setAppValue('migrate_to_ocis', 'instance_verified', 'yes');
		return 0;
	}

	private function hasValidEMail(IUser $user): bool {
		$email = $user->getEMailAddress();
		if ($email === null) {
			$this->writeln("<error>No Email for user {$user->getUID()} - it cannot be migrated to ownCloud InfiniteScale!</error>");
			return false;
		}
		$validMailAddress = EMailAddress::validateMailAddress($email);
		if (!$validMailAddress) {
			$this->writeln("<error>No valid Email for user {$user->getUID()}: $email - it cannot be migrated to ownCloud InfiniteScale!</error>");
			return false;
		}
		return true;
	}

	private function verifyUsers(): bool {
		$verified = true;
		$email_addresses = [];
		# ensure all users have an email address ...
		$this->userManager->callForUsers(function (IUser $user) use (&$verified, &$email_addresses) {
			if (!$user->isEnabled()) {
				$this->writeln("<warn>Disabled user {$user->getUID()} - it cannot be migrated to ownCloud InfiniteScale!</warn>");
				return;
			}
			if (!$this->hasValidEMail($user)) {
				$verified = false;
			} else {
				# save users by their email addresses ...
				$email_address = strtolower($user->getEMailAddress());
				$a = $email_addresses[$email_address] ?? [];
				$a[]= $user;
				$email_addresses[$email_address] = $a;
			}
		});

		# detect duplicate email addresses
		$sup_email_addresses = array_filter($email_addresses, static function (array $a) {
			return \count($a) > 1;
		});
		if (\count($sup_email_addresses) > 0) {
			$this->writeln('<error>Some users are sharing the same email address.</error>');
			$this->writeln('<error>This can lead to unexpected behavior.</error>');
			$this->writeln('');
			$this->writeln('<error>Please assign unique email addresses to all users.</error>');
			$this->writeln('');
			foreach ($sup_email_addresses as $users) {
				/** @var IUser[] $users */
				foreach ($users as $u) {
					$this->writeln(" - {$u->getEMailAddress()}: {$u->getUID()}");
				}
			}
			return false;
		}

		return $verified;
	}
}
