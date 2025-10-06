<?php
namespace OCA\MigrateToInfiniteScale\Helper;

use Exception;
use JsonException;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IWebDavClientService;
use OCP\IUser;
use OCP\IGroup;

class OCISClient {
	private IClient $client;
	private IWebDavClientService $webdavCS;
	private string $ocis_host;
	private bool $insecure;

	public function __construct(IClient $client, IWebDavClientService $webdavCS, string $ocis_host, bool $insecure) {
		$this->client = $client;
		$this->webdavCS = $webdavCS;
		$this->ocis_host = $ocis_host;
		$this->insecure = $insecure;
	}

	/**
	 * @throws JsonException
	 * @throws Exception
	 */
	public function tokenExchange(string $admin_user, string $admin_password, string $user_name) {
		$resp = $this->client->post("https://$this->ocis_host/auth-app/tokens", [
			'http_errors' => false,
			'auth' => [
				$admin_user,
				$admin_password
			],
			'query' => [
				'expiry' => '24h',
				'userName' => $user_name
			],
			'verify' => !$this->insecure,
		]);
		if ($resp->getStatusCode() === 502) {
			throw new \RuntimeException('App Token API is not enabled!');
		}
		if ($resp->getStatusCode() !== 200 && $resp->getStatusCode() !== 201) {
			throw new \RuntimeException('Failed to create app token.');
		}
		$json = json_decode($resp->getBody(), true, 512, JSON_THROW_ON_ERROR);
		$token = $json['token'] ?? null;
		if ($token === null) {
			throw new \RuntimeException('Failed to create app token.');
		}

		return $token;
	}

	// TODO: need to remove used tokens
	//public function tokenRemove(string $admin_user, string $admin_password, string $token) {
	//	$resp = $this->client->delete("https://$this->ocis_host/auth-app/tokens", [
	//		'http_errors' => false,
	//		'auth' => [$admin_user, $admin_password],
	//		'query' => [
	//			'token' => $token,
	//		],
	//		'verify' => !$this->insecure,
	//	]);
	//	return $resp->getStatusCode();
	//}

	/**
	 * @throws JsonException
	 */
	public function createUser(string $token, IUser $user): ?array {
		$resp = $this->client->post("https://$this->ocis_host/graph/v1.0/users", [
			'http_errors' => false,
			'auth' => [
				'admin',
				$token
			],
			'json' => [
				'mail' => $user->getEMailAddress(),
				'displayName' => $user->getDisplayName(),
				'onPremisesSamAccountName' => $user->getUID(),
			],
			'verify' => !$this->insecure,
		]);
		$body = $resp->getBody();
		$body = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
		if ($resp->getStatusCode() === 201) {
			return $body;
		}
		$errorCode = $body['error']['code'] ?? '';
		if ($errorCode === 'nameAlreadyExists' && $resp->getStatusCode() === 409) {
			return null;
		}

		throw new \RuntimeException("Failed to create user! Error: $errorCode");
	}

	/**
	 * Search the oCIS users based on the OC10 user's username.
	 * The whole json response (which might include multiple matches) will
	 * be returned on success.
	 */
	public function searchUsers(string $token, IUser $user): array {
		$resp = $this->client->get("https://$this->ocis_host/graph/v1.0/users", [
			'http_errors' => false,
			'auth' => [
				'admin',
				$token
			],
			'query' => [
				'$search' => "\"{$user->getUserName()}\"",
			],
			'verify' => !$this->insecure,
		]);
		$body = $resp->getBody();
		$body = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
		if ($resp->getStatusCode() === 200) {
			return $body;
		}
		$errorCode = $body['error']['code'] ?? '';

		throw new \RuntimeException("Failed to search user! Error: $errorCode");
	}

	/**
	 * Check if the user exists in oCIS. If the user exists in oCIS, the oCIS'
	 * user data is returned, otherwise null is returned.
	 * Due to the id will be different between both system, the matching will be
	 * done using the username (and oCIS' "onPremisesSamAccountName").
	 * First exact match will be returned.
	 * NOTE: Wrong user might be returned if there are duplicates.
	 */
	public function checkUser(string $token, IUser $user): ?array {
		$usersFound = $this->searchUsers($token, $user);
		foreach ($usersFound['value'] as $userFound) {
			if ($userFound['onPremisesSamAccountName'] === $user->getUserName()) {
				return $userFound;
			}
		}
		return null;
	}

	/**
	 * Create a group using the same display name as the OC10 group.
	 * The oCIS group information will be returned on success; null if the
	 * group already exists.
	 */
	public function createGroup(string $token, IGroup $group): ?array {
		$resp = $this->client->post("https://$this->ocis_host/graph/v1.0/groups", [
			'http_errors' => false,
			'auth' => [
				'admin',
				$token
			],
			'json' => [
				'displayName' => $group->getDisplayName(),
			],
			'verify' => !$this->insecure,
		]);
		$body = $resp->getBody();
		$body = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
		if ($resp->getStatusCode() === 201) {
			return $body;
		}
		$errorCode = $body['error']['code'] ?? '';
		if ($errorCode === 'nameAlreadyExists' && $resp->getStatusCode() === 409) {
			return null;
		}

		throw new \RuntimeException("Failed to create group! Error: $errorCode");
	}

	/**
	 * Search the oCIS groups based on the OC10 group's display name.
	 * The whole json response (which might include multiple matches) will
	 * be returned on success.
	 */
	public function searchGroups(string $token, IGroup $group): array {
		$resp = $this->client->get("https://$this->ocis_host/graph/v1.0/groups", [
			'http_errors' => false,
			'auth' => [
				'admin',
				$token
			],
			'query' => [
				'$search' => "\"{$group->getDisplayName()}\"",
			],
			'verify' => !$this->insecure,
		]);
		$body = $resp->getBody();
		$body = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
		if ($resp->getStatusCode() === 200) {
			return $body;
		}
		$errorCode = $body['error']['code'] ?? '';

		throw new \RuntimeException("Failed to search group! Error: $errorCode");
	}

	/**
	 * Check if the group exists in oCIS. If the group exists in oCIS, the oCIS'
	 * group data is returned, otherwise null is returned.
	 * Due to the id will be different between both system, the matching will be
	 * done using the display name. First exact match will be returned.
	 * NOTE: Wrong group might be returned if there are duplicates.
	 */
	public function checkGroup(string $token, IGroup $group): ?array {
		$groupsFound = $this->searchGroups($token, $group);
		foreach ($groupsFound['value'] as $groupFound) {
			if ($groupFound['displayName'] === $group->getDisplayName()) {
				return $groupFound;
			}
		}
		return null;
	}

	public function addMemberToGroup(string $token, string $ocisGroupId, string $ocisUserId) {
		$resp = $this->client->post("https://$this->ocis_host/graph/v1.0/groups/{$ocisGroupId}/members/\$ref", [
			'http_errors' => false,
			'auth' => [
				'admin',
				$token
			],
			'json' => [
				'@odata.id' => "https://$this->ocis_host/graph/v1.0/users/$ocisUserId",
			],
			'verify' => !$this->insecure,
		]);
		if ($resp->getStatusCode() === 204) {
			return true;
		}
		$body = $resp->getBody();
		$body = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
		$errorCode = $body['error']['code'] ?? '';
		if ($errorCode === 'nameAlreadyExists' && $resp->getStatusCode() === 409) {
			return null;
		}

		throw new \RuntimeException("Failed to add member to group! Error: $errorCode");
	}

	public function shareInvite(string $token, string $userID, array $shareInviteData): array {
		$driveId = $shareInviteData['driveId'];
		$itemId = $shareInviteData['itemId'];
		$recipientType = $shareInviteData['recipientType'];
		$recipientId = $shareInviteData['recipientId'];
		$roleId = $shareInviteData['roleId'];
		$expiration = $shareInviteData['expiration'];

		$jsonData = [
			'recipients' => [
				[
					'@libre.graph.recipient.type' =>  $recipientType,
					'objectId' => $recipientId,
				],
			],
			'roles' => [$roleId],
		];
		if ($expiration) {
			$jsonData['expirationDateTime'] = $expiration;
		}

		$resp = $this->client->post("https://$this->ocis_host/graph/v1beta1/drives/$driveId/items/$itemId/invite", [
			'http_errors' => false,
			'auth' => [
				$userID,
				$token
			],
			'json' => $jsonData,
			'verify' => !$this->insecure,
		]);

		$body = $resp->getBody();
		$body = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
		if ($resp->getStatusCode() === 200) {
			return $body;
		}
		$errorCode = $body['error']['code'] ?? '';

		throw new \RuntimeException("Failed to share invite! Error: $errorCode");
	}

	/**
	 * Get a list containing the share roles defined in oCIS
	 */
	public function getShareRoles(string $token): array {
		$resp = $this->client->get("https://$this->ocis_host/graph/v1beta1/roleManagement/permissions/roleDefinitions", [
			'http_errors' => false,
			'auth' => [
				'admin',
				$token
			],
			'verify' => !$this->insecure,
		]);
		$body = $resp->getBody();
		if ($resp->getStatusCode() !== 200) {
			throw new \RuntimeException("Failed to fetch share roles! Status: {$resp->getStatusCode()}");
		}
		$decodedBody = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
		return $decodedBody;
	}

	public function getApplications(string $token): array {
		$resp = $this->client->get("https://$this->ocis_host/graph/v1.0/applications", [
			'http_errors' => false,
			'auth' => [
				'admin',
				$token
			],
			'verify' => !$this->insecure,
		]);
		$body = $resp->getBody();
		if ($resp->getStatusCode() !== 200) {
			throw new \RuntimeException("Failed to fetch roles! Status: {$resp->getStatusCode()}");
		}
		$decodedBody = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

		if (!\is_array($decodedBody) || !isset($decodedBody['value'])) {
			throw new \RuntimeException("Failed to fetch roles!");
		}
		return $decodedBody['value'];
	}

	public function assignRole(string $token, string $ocisUserId, string $ocisRoleId, string $ocisResourceId) {
		$resp = $this->client->post("https://$this->ocis_host/graph/v1.0/users/$ocisUserId/appRoleAssignments", [
			'http_errors' => false,
			'auth' => [
				'admin',
				$token
			],
			'json' => [
				'appRoleId' => $ocisRoleId,
				'principalId' => $ocisUserId,
				'resourceId' => $ocisResourceId,
			],
			'verify' => !$this->insecure,
		]);
		$body = $resp->getBody();
		if ($resp->getStatusCode() !== 201) {
			throw new \RuntimeException("Failed to assign roles! Status: {$resp->getStatusCode()}");
		}
		$decodedBody = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

		return $decodedBody;
	}

	/**
	 * Get a list with the information of all personal drives for the user.
	 * The list is expected to have only one item (only one personal drive).
	 *
	 * The $userID and $token will be used for authentication
	 */
	public function getPersonalDrives(string $token, string $userID): array {
		$resp = $this->client->get("https://$this->ocis_host/graph/v1.0/me/drives", [
			'http_errors' => false,
			'auth' => [
				$userID,
				$token
			],
			'verify' => !$this->insecure,
		]);
		$body = $resp->getBody();
		if ($resp->getStatusCode() !== 200) {
			throw new \RuntimeException("Failed to get personal drives! Status: {$resp->getStatusCode()}");
		}
		$decodedBody = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

		if (!isset($decodedBody['value']) || !\is_array($decodedBody['value'])) {
			return [];
		}
		// return only personal drives
		$drives = [];
		foreach ($decodedBody['value'] as $driveInfo) {
			if (isset($driveInfo['driveType']) && $driveInfo['driveType'] === 'personal') {
				$drives[] = $driveInfo;
			}
		}
		return $drives;
	}

	/**
	 * Get a webdav client to access to the provided drive.
	 * The $userID and $token will be used to authenticate against
	 * the drive.
	 * Note that SSL certificates might need to be installed to access
	 * the drive unless the "insecure" flag is on (disabling certificate
	 * verification)
	 * @return \Sabre\DAV\Client
	 */
	public function getWebdavClientForDrive(string $token, string $userID, array $driveInfo): \Sabre\DAV\Client {
		$webdavClient = $this->webdavCS->newClient([
			'baseUri' => $driveInfo['root']['webDavUrl'] . '/',
			'userName' => $userID,
			'password' => $token,
			'authType' => \Sabre\DAV\Client::AUTH_BASIC,
		]);

		if ($this->insecure) {
			$webdavClient->addCurlSetting(CURLOPT_SSL_VERIFYPEER, false);
			$webdavClient->addCurlSetting(CURLOPT_SSL_VERIFYHOST, false);
		}

		// newClient returns a \Sabre\DAV\Client although it's
		// documented to return a \Sabre\HTTP\Client. We need the
		// dav client though.
		//
		// @phpstan-ignore-next-line
		return $webdavClient;
	}

	/**
	 * Get the oCIS file info for the target path.
	 * The path refers to a OC10 path that must have been migrated
	 */
	public function getOcisFileInfo(string $token, \Sabre\DAV\Client $davClient, string $path): array {
		$targetPath = "ownCloud/{$path}";
		$data = $davClient->propFind($targetPath, ['{http://owncloud.org/ns}fileid']);

		return $data;
	}
}
