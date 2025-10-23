<?php

namespace OCA\MigrateToInfiniteScale\MigrationState;

use OCA\MigrateToInfiniteScale\MigrationState\Migration;
use OCA\MigrateToInfiniteScale\MigrationState\StateMigrateUsers;
use OCA\MigrateToInfiniteScale\Helper\EMailAddress;
use OCP\IUserManager;
use OCP\IUser;

class StateVerify implements State {
	/** @var IUserManager */
	private IUserManager $userManager;

	/**
	 * @param IUserManager $userManager
	 */
	public function __construct(IUserManager $userManager) {
		$this->userManager = $userManager;
	}

	/**
	 * Verify the OC10 is ready to be migrated. The main requisite is that
	 * all user must have an unique email
	 *
	 * Required params:
	 * - 'output' -> a Symfony's OutputInterface to write messages
	 *
	 * Move to StateMigrateUsers on success.
	 *
	 * @throws MigrateException
	 */
	public function migrate(array $params, Migration $migration) {
		/** @var \Symfony\Component\Console\Output\OutputInterface $output */
		$output = $params['output'];

		$verified = true;
		$email_addresses = [];
		# ensure all users have an email address ...
		$this->userManager->callForUsers(function (IUser $user) use (&$verified, &$email_addresses, $output) {
			if (!$user->isEnabled()) {
				$output->writeln("<fg=red;options=bold>Disabled user {$user->getUID()} - it will be skipped!</>");
				return;
			}
			if (!$this->hasValidEMail($user)) {
				$output->writeln("<error>{$user->getUID()} has an invalid email</error>");
				$verified = false;
			} else {
				# save users by their email addresses ...
				$email_address = strtolower($user->getEMailAddress());
				$a = $email_addresses[$email_address] ?? [];
				$a[] = $user;
				$email_addresses[$email_address] = $a;
			}
		});

		if (!$verified) {
			throw new MigrateException("Some users have invalid emails");
		}

		# detect duplicate email addresses
		$sup_email_addresses = array_filter($email_addresses, static function (array $a) {
			return \count($a) > 1;
		});
		if (\count($sup_email_addresses) > 0) {
			$output->writeln('<error>Some users are sharing the same email address.</error>');
			$output->writeln('<error>This can lead to unexpected behavior.</error>');
			$output->writeln('');
			$output->writeln('<error>Please assign unique email addresses to all users.</error>');
			$output->writeln('');
			foreach ($sup_email_addresses as $users) {
				/** @var IUser[] $users */
				foreach ($users as $u) {
					$output->writeln(" - {$u->getEMailAddress()}: {$u->getUID()}");
				}
			}
			throw new MigrateException('Please make sure all users meet the requirements.');
		}

		$migration->switchState(StateMigrateUsers::class);
	}

	private function hasValidEMail(IUser $user): bool {
		$email = $user->getEMailAddress();
		if ($email === null) {
			return false;
		}
		$validMailAddress = EMailAddress::validateMailAddress($email);
		if (!$validMailAddress) {
			return false;
		}
		return true;
	}

	public function associatedCommand(): string {
		return 'migrate:to-ocis:verify';
	}
}
