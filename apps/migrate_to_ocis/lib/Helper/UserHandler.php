<?php
// SPDX-License-Identifier: Apache-2.0
namespace OCA\MigrateToInfiniteScale\Helper;

use OCP\IUser;
use OCA\MigrateToInfiniteScale\OCIS\ClientException;

class UserHandler {
	/** @var UserGroupFinder */
	private UserGroupFinder $userGroupFinder;

	/**
	 * @param UserGroupFinder $userGroupFinder
	 */
	public function __construct(UserGroupFinder $userGroupFinder) {
		$this->userGroupFinder = $userGroupFinder;
	}

	/**
	 * Check if the target user can be migrated to oCIS. The user must
	 * has an email address and musn't be disabled.
	 * LDAP users won't be migrated
	 *
	 * @param IUser $user the ownCloud Classic user to check
	 * @return bool
	 */
	public function canBeMigrated(IUser $user): bool {
		if ($user->getEMailAddress() === null || !$user->isEnabled()) {
			return false;
		}

		$backend = $user->getBackendClassName();
		if ($backend === 'LDAP' || $backend === 'OCA\User_LDAP\User_Proxy') {
			return false;
		}

		return true;
	}

	/**
	 * Check if the target ownCloud Classic user has been migrated to oCIS. This method
	 * will use local data (via UserGroupFinder cache) if possible, and
	 * perform a request to oCIS otherwise.
	 *
	 * @param string $admin_user
	 * @param string $token
	 * @param IUser $user
	 * @return bool
	 */
	public function hasBeenMigrated(string $admin_user, string $token, IUser $user): bool {
		try {
			$user = $this->userGroupFinder->getUser($admin_user, $token, $user);
			return $user !== null;
		} catch (ClientException $ex) {
			return false;
		}
	}
}
