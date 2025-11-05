<?php
namespace OCA\MigrateToInfiniteScale\Helper;

use OCP\IUser;
use OCP\IGroup;
use OCP\IUserManager;
use OCP\IGroupManager;
use OCP\ITempManager;
use OCA\MigrateToInfiniteScale\OCIS\Client;

class UserGroupFinder {
	private const CACHE_FILENAME = 'migrateToOcis-userGroupFinder.cache';
	/**
	 * map OC10 username -> ocis user id
	 * @var array<string, string>
	 */
	private array $userCache = [];
	/**
	 * map OC10 group displayname -> ocis group id.
	 * NOTE: display name must be unique or it will be overwritten
	 * @var array<string, string>
	 */
	private array $groupCache = [];
	private Client $ocisClient;
	private IUserManager $userManager;
	private IGroupManager $groupManager;
	private ITempManager $tempManager;

	/**
	 * @param Client $ocisClient client to make requests if needed
	 * @param IUserManager $userManager get OC10 users by id if needed
	 * @param IGroupManager $groupManager get OC10 groups by id if needed
	 */
	public function __construct(Client $ocisClient, IUserManager $userManager, IGroupManager $groupManager, ITempManager $tempManager) {
		$this->ocisClient = $ocisClient;
		$this->userManager = $userManager;
		$this->groupManager = $groupManager;
		$this->tempManager = $tempManager;
	}

	/**
	 * Add a OC10 user with the corresponding oCIS user id to the cache.
	 * This is intended to be used during oCIS user creation, when we
	 * already have the OC10 user and we get the oCIS user id as part of
	 * the creation of the oCIS user.
	 */
	public function addUserToCache(IUser $user, string $ocisUserID) {
		$this->userCache[$user->getUserName()] = $ocisUserID;
	}

	/**
	 * Add a OC10 group with the corresponding oCIS group id to the cache.
	 * This is intended to be used during oCIS group creation, when we
	 * already have the OC10 group and we get the oCIS group id as part of
	 * the creation of the oCIS group.
	 */
	public function addGroupToCache(IGroup $group, string $ocisGroupID) {
		$this->groupCache[$group->getDisplayName()] = $ocisGroupID;
	}

	/**
	 * Get the oCIS user id that matches the OC10 user. If the user isn't
	 * found in the cache, a request will be sent to oCIS to find the user.
	 * If the user isn't found is oCIS neither, null will be returned.
	 *
	 * @param string $token oCIS admin's app password to make the
	 * request to oCIS if needed
	 * @param IUser $user the OC10 user
	 * @return string|null the matched oCIS user id or null if the user
	 * isn't found
	 * @throws \OCA\MigrateToInfiniteScale\OCIS\ClientException if tries
	 * to connect to oCIS and fails
	 */
	public function getUser(string $admin_user, string $token, IUser $user): ?string {
		$username = $user->getUserName();
		if (!isset($this->userCache[$username])) {
			// find user in ocis
			$foundUser = $this->ocisClient->checkUser($admin_user, $token, $user);
			if ($foundUser) {
				$this->userCache[$username] = $foundUser['id'];
			}
		}

		return $this->userCache[$username] ?? null;
	}

	/**
	 * Get the oCIS group id that matches the OC10 group. If the group isn't
	 * found in the cache, a request will be sent to oCIS to find the group.
	 * If the group isn't found is oCIS neither, null will be returned.
	 *
	 * Note: we're caching groups based on the group's display name
	 *
	 * @param string $token oCIS admin's app password to make the
	 * request to oCIS if needed
	 * @param IGroup $group the OC10 group
	 * @return string|null the matched oCIS group id or null if the group
	 * isn't found
	 * @throws \OCA\MigrateToInfiniteScale\OCIS\ClientException if tries
	 * to connect to oCIS and fails
	 */
	public function getGroup(string $admin_user, string $token, IGroup $group): ?string {
		$groupname = $group->getDisplayName();
		if (!isset($this->groupCache[$groupname])) {
			// find group in ocis
			$foundGroup = $this->ocisClient->checkGroup($admin_user, $token, $group);
			if ($foundGroup) {
				$this->groupCache[$groupname] = $foundGroup['id'];
			}
		}

		return $this->groupCache[$groupname] ?? null;
	}

	/**
	 * Same as "getUser" but we use the OC10 user id instead.
	 * The OC10 user manager will be used to find the user that will
	 * be matched.
	 *
	 * @param string $token oCIS admin's app password to make the
	 * request to oCIS if needed
	 * @param string $oc10UserId the OC10 user id
	 * @return string|null the matched oCIS user id or null if the user
	 * isn't found
	 * @throws \OCA\MigrateToInfiniteScale\OCIS\ClientException if tries
	 * to connect to oCIS and fails
	 */
	public function getUserById(string $admin_user, string $token, string $oc10UserId): ?string {
		$user = $this->userManager->get($oc10UserId);
		if ($user) {
			return $this->getUser($admin_user, $token, $user);
		}
		return null;
	}

	/**
	 * Same as "getGroup" but we use the OC10 group id instead.
	 * The OC10 group manager will be used to find the group that will
	 * be matched.
	 *
	 * @param string $token oCIS admin's app password to make the
	 * request to oCIS if needed
	 * @param string $oc10GroupId the OC10 group id
	 * @return string|null the matched oCIS group id or null if the group
	 * isn't found
	 * @throws \OCA\MigrateToInfiniteScale\OCIS\ClientException if tries
	 * to connect to oCIS and fails
	 */
	public function getGroupById(string $admin_user, string $token, string $oc10GroupId) {
		$group = $this->groupManager->get($oc10GroupId);
		if ($group) {
			return $this->getGroup($admin_user, $token, $group);
		}
		return null;
	}

	/**
	 * Save the cache in a temporary file for a later use.
	 * The filename is fixed, and it will be saved in the temporary
	 * directory.
	 * @throws \UnexpectedValueException
	 */
	public function saveCache() {
		$tmpFolder = $this->tempManager->getTempBaseDir();
		if ($tmpFolder === '') {
			throw new \UnexpectedValueException('Temporary folder is not set');
		}

		$targetFile = $tmpFolder . '/' . self::CACHE_FILENAME;
		$content = \json_encode([
			'users' => $this->userCache,
			'groups' => $this->groupCache,
		]);

		$filePointer = \fopen($targetFile, 'c');
		if (!\flock($filePointer, LOCK_EX)) {
			throw new \UnexpectedValueException("Cannot get lock on file $targetFile");
		}

		\ftruncate($filePointer, 0);
		\fwrite($filePointer, $content);
		\fflush($filePointer);
		\flock($filePointer, LOCK_UN);
		\fclose($filePointer);
	}

	/**
	 * Load the cache previously saved with "saveCache".
	 * Note that this will overwrite any value that this instance had
	 * before this call.
	 * @throws \UnexpectedValueException
	 */
	public function loadCache() {
		$tmpFolder = $this->tempManager->getTempBaseDir();
		if ($tmpFolder === '') {
			throw new \UnexpectedValueException('Temporary folder is not set');
		}

		$targetFile = $tmpFolder . '/' . self::CACHE_FILENAME;

		$filePointer = \fopen($targetFile, 'r');
		if (!\flock($filePointer, LOCK_EX)) {
			throw new \UnexpectedValueException("Cannot get lock on file $targetFile");
		}

		\ftruncate($filePointer, 0);
		$content = \fread($filePointer, \filesize($targetFile));  // need to read the whole file
		\flock($filePointer, LOCK_UN);
		\fclose($filePointer);

		$data = \json_decode($content, true);
		if (!\is_array($data)) {
			throw new \UnexpectedValueException("Failed to decode json data on $targetFile");
		}

		$this->userCache = $data['users'];
		$this->groupCache = $data['groups'];
	}

	public function cleanCache() {
		$tmpFolder = $this->tempManager->getTempBaseDir();
		if ($tmpFolder === '') {
			// file can't be saved here
			return;
		}

		$targetFile = $tmpFolder . '/' . self::CACHE_FILENAME;
		\unlink($targetFile);
	}
}
