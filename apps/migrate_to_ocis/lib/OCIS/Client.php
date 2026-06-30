<?php
// SPDX-License-Identifier: Apache-2.0
namespace OCA\MigrateToInfiniteScale\OCIS;

use OCP\Http\Client\IClient;
use OCP\Http\Client\IWebDavClientService;
use OCP\IUser;
use OCP\IGroup;
use Sabre\HTTP\ClientHttpException;

class Client {
	private IClient $client;
	private IWebDavClientService $webdavCS;
	private string $ocis_host;
	private bool $insecure = false;

	public function __construct(IClient $client, IWebDavClientService $webdavCS, string $ocis_host, bool $insecure) {
		$this->client = $client;
		$this->webdavCS = $webdavCS;
		$this->ocis_host = $ocis_host;
		$this->insecure = $insecure;
	}

	/**
	 * @param string $admin_user admin user for the auth
	 * @param string $admin_password admin password for the auth
	 * @param string $user_name the user we want to generate the token for
	 * @return string the generated token
	 * @throws ClientException
	 */
	public function tokenExchange(string $admin_user, string $admin_password, string $user_name): string {
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
			throw new ClientException('App Token API is not enabled!', __FUNCTION__, $resp);
		}
		if ($resp->getStatusCode() !== 200 && $resp->getStatusCode() !== 201) {
			throw new ClientException('Failed to create app token.', __FUNCTION__, $resp);
		}

		$json = \json_decode($resp->getBody(), true);
		$token = $json['token'] ?? null;
		if ($token === null) {
			throw new ClientException('Failed to create app token.', __FUNCTION__, $resp);
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
	 * Create a new user in oCIS using the OC10 user information.
	 * If the user already exists, a ClientException will be thrown with
	 * a 409 error code.
	 *
	 * @param string $admin_user
	 * @param string $token app-auth token or password for the admin user
	 * @param IUser $user the OC10 user we want to create in oCIS
	 * @return array the created user information returned by the request
	 * @throws ClientException in case of failure.
	 */
	public function createUser(string $admin_user, string $token, IUser $user): array {
		$resp = $this->client->post("https://$this->ocis_host/graph/v1.0/users", [
			'http_errors' => false,
			'auth' => [
				$admin_user,
				$token
			],
			'json' => [
				'mail' => $user->getEMailAddress(),
				'displayName' => $user->getDisplayName(),
				'onPremisesSamAccountName' => $user->getUserName(),
			],
			'verify' => !$this->insecure,
		]);

		if ($resp->getStatusCode() !== 201) {
			throw new ClientException("Failed to create user", __FUNCTION__, $resp);
		}

		$decodedBody = \json_decode($resp->getBody(), true);
		if ($decodedBody === null) {
			throw new ClientException("Failed to create user: failed to parse response", __FUNCTION__, $resp);
		}
		return $decodedBody;
	}

	/**
	 * Search the oCIS users based on the OC10 user's username.
	 * The whole json response (which might include multiple matches) will
	 * be returned on success.
	 *
	 * @param string $admin_user
	 * @param string $token
	 * @param IUser $user the OC10 user to be searched in oCIS
	 * @return array the list of users found, as returned by the request
	 * @throws ClientException in case of failure
	 */
	public function searchUsers(string $admin_user, string $token, IUser $user): array {
		$resp = $this->client->get("https://$this->ocis_host/graph/v1.0/users", [
			'http_errors' => false,
			'auth' => [
				$admin_user,
				$token
			],
			'query' => [
				'$search' => "\"{$user->getUserName()}\"",
			],
			'verify' => !$this->insecure,
		]);

		if ($resp->getStatusCode() !== 200) {
			throw new ClientException("Failed to search users", __FUNCTION__, $resp);
		}

		$decodedBody = \json_decode($resp->getBody(), true);
		if ($decodedBody === null) {
			throw new ClientException("Failed to search users: failed to parse response", __FUNCTION__, $resp);
		}
		return $decodedBody;
	}

	/**
	 * Check if the user exists in oCIS. If the user exists in oCIS, the oCIS'
	 * user data is returned, otherwise null is returned.
	 * Due to the id will be different between both system, the matching will be
	 * done using the username (and oCIS' "onPremisesSamAccountName").
	 * First exact match will be returned.
	 * NOTE: Wrong user might be returned if there are duplicates.
	 *
	 * @param string $admin_user
	 * @param string $token
	 * @param IUser $user the OC10 user to check in oCIS
	 * @return array|null the oCIS user information found or null if no user
	 * is found
	 * @throws ClientException in case of failure
	 */
	public function checkUser(string $admin_user, string $token, IUser $user): ?array {
		$usersFound = $this->searchUsers($admin_user, $token, $user);
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
	 *
	 * @param string $admin_user
	 * @param string $token
	 * @param IGroup $group the OC10 group we want to create in oCIS
	 * @return array the created group information returned by the request
	 * @throws ClientException in case of failure.
	 */
	public function createGroup(string $admin_user, string $token, IGroup $group): array {
		$resp = $this->client->post("https://$this->ocis_host/graph/v1.0/groups", [
			'http_errors' => false,
			'auth' => [
				$admin_user,
				$token
			],
			'json' => [
				'displayName' => $group->getDisplayName(),
			],
			'verify' => !$this->insecure,
		]);

		if ($resp->getStatusCode() !== 201) {
			throw new ClientException("Failed to create group", __FUNCTION__, $resp);
		}

		$decodedBody = \json_decode($resp->getBody(), true);
		if ($decodedBody === null) {
			throw new ClientException("Failed to create group: failed to parse response", __FUNCTION__, $resp);
		}
		return $decodedBody;
	}

	/**
	 * Search the oCIS groups based on the OC10 group's display name.
	 * The whole json response (which might include multiple matches) will
	 * be returned on success.
	 *
	 * @param string $admin_user
	 * @param string $token
	 * @param IGroup $group the OC10 group to be searched in oCIS
	 * @return array the list of groups found, as returned by the request
	 * @throws ClientException in case of failure
	 */
	public function searchGroups(string $admin_user, string $token, IGroup $group): array {
		$resp = $this->client->get("https://$this->ocis_host/graph/v1.0/groups", [
			'http_errors' => false,
			'auth' => [
				$admin_user,
				$token
			],
			'query' => [
				'$search' => "\"{$group->getDisplayName()}\"",
			],
			'verify' => !$this->insecure,
		]);

		if ($resp->getStatusCode() !== 200) {
			throw new ClientException("Failed to search groups", __FUNCTION__, $resp);
		}

		$decodedBody = \json_decode($resp->getBody(), true);
		if ($decodedBody === null) {
			throw new ClientException("Failed to search groups: failed to parse response", __FUNCTION__, $resp);
		}
		return $decodedBody;
	}

	/**
	 * Check if the group exists in oCIS. If the group exists in oCIS, the oCIS'
	 * group data is returned, otherwise null is returned.
	 * Due to the id will be different between both system, the matching will be
	 * done using the display name. First exact match will be returned.
	 * NOTE: Wrong group might be returned if there are duplicates.
	 *
	 * @param string $admin_user
	 * @param string $token
	 * @param IGroup $group the OC10 group to check in oCIS
	 * @return array|null the oCIS group information found or null if no group
	 * is found
	 * @throws ClientException in case of failure
	 */
	public function checkGroup(string $admin_user, string $token, IGroup $group): ?array {
		$groupsFound = $this->searchGroups($admin_user, $token, $group);
		foreach ($groupsFound['value'] as $groupFound) {
			if ($groupFound['displayName'] === $group->getDisplayName()) {
				return $groupFound;
			}
		}
		return null;
	}

	/**
	 * @param string $admin_user
	 * @param string $token
	 * @param string $ocisGroupId the target oCIS group id
	 * @param string $ocisUserId the target oCIS user id to be added to the $ocisGroupId
	 * @throws ClientException
	 */
	public function addMemberToGroup(string $admin_user, string $token, string $ocisGroupId, string $ocisUserId) {
		$resp = $this->client->post("https://$this->ocis_host/graph/v1.0/groups/{$ocisGroupId}/members/\$ref", [
			'http_errors' => false,
			'auth' => [
				$admin_user,
				$token
			],
			'json' => [
				'@odata.id' => "https://$this->ocis_host/graph/v1.0/users/$ocisUserId",
			],
			'verify' => !$this->insecure,
		]);

		if ($resp->getStatusCode() !== 204) {
			throw new ClientException("Failed to add member to the group", __FUNCTION__, $resp);
		}

		// No body content is expected on success. This function won't return anything neither
	}

	/**
	 * @param string $userID the onPremisesSamAccountName of the oCIS
	 * user sharing the data (used for authentication)
	 * @param string $token password for the user
	 * @param array $shareInviteData parameters required for the invitation
	 * - 'driveId': the oCIS drive id where the file is located
	 * - 'itemId': the item/file id to be shared
	 * - 'recipientType': either "user" or "group"
	 * - 'recipientId': the oCIS id of the recipient
	 * - 'roleId': the id of the share role to be used (can view, can edit...)
	 * - 'expiration': the expiration date, in RFC3339 format. (can be null)
	 * @return array the response of the request
	 */
	public function shareInvite(string $userID, string $token, array $shareInviteData): array {
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

		$statusCode = $resp->getStatusCode();
		if ($statusCode !== 200 && $statusCode !== 207) {
			throw new ClientException("Failed to invite", __FUNCTION__, $resp);
		}

		$decodedBody = \json_decode($resp->getBody(), true);
		if ($decodedBody === null) {
			throw new ClientException("Failed to invite: failed to parse response", __FUNCTION__, $resp);
		}
		return $decodedBody;
	}

	/**
	 * @param string $userID the onPremisesSamAccountName of the oCIS
	 * user sharing the data (used for authentication)
	 * @param string $token password for the user
	 * @param array $shareLinkData parameters required for the invitation
	 * - 'driveId': the oCIS drive id where the file is located
	 * - 'itemId': the item/file id to be shared
	 * - 'type': the link type, such as "view", "edit", "createOnly"...
	 * - 'expiration': the expiration date, in RFC3339 format. (can be null)
	 * - 'password': the password for the link. (can be null)
	 * @return array the response of the request
	 */
	public function shareLink(string $userID, string $token, array $shareLinkData): array {
		$driveId = $shareLinkData['driveId'];
		$itemId = $shareLinkData['itemId'];
		$type = $shareLinkData['type'];
		$expiration = $shareLinkData['expiration'];
		$password = $shareLinkData['password'];

		$jsonData = ['type' => $type];
		if ($expiration) {
			$jsonData['expirationDateTime'] = $expiration;
		}
		if ($password) {
			$jsonData['password'] = $password;
		}

		$resp = $this->client->post("https://$this->ocis_host/graph/v1beta1/drives/$driveId/items/$itemId/createLink", [
			'http_errors' => false,
			'auth' => [
				$userID,
				$token
			],
			'json' => $jsonData,
			'verify' => !$this->insecure,
		]);

		$statusCode = $resp->getStatusCode();
		if ($statusCode !== 200 && $statusCode !== 207) {
			throw new ClientException("Failed to share by link", __FUNCTION__, $resp);
		}

		$decodedBody = \json_decode($resp->getBody(), true);
		if ($decodedBody === null) {
			throw new ClientException("Failed to share by link: failed to parse response", __FUNCTION__, $resp);
		}
		return $decodedBody;
	}

	/**
	 * Get a list containing the share roles defined in oCIS
	 * @param string $admin_user
	 * @param string $token
	 * @return array the list of roles, as returned by the request
	 * @throws ClientException
	 */
	public function getShareRoles(string $admin_user, string $token): array {
		$resp = $this->client->get("https://$this->ocis_host/graph/v1beta1/roleManagement/permissions/roleDefinitions", [
			'http_errors' => false,
			'auth' => [
				$admin_user,
				$token
			],
			'verify' => !$this->insecure,
		]);

		if ($resp->getStatusCode() !== 200) {
			throw new ClientException("Failed to fetch share roles", __FUNCTION__, $resp);
		}

		$decodedBody = \json_decode($resp->getBody(), true);
		if ($decodedBody === null) {
			throw new ClientException("Failed to fetch share roles: failed to parse response", __FUNCTION__, $resp);
		}
		return $decodedBody;
	}

	/**
	 * Get the application with the user roles they introduce
	 * @param string $admin_user
	 * @param string $token
	 * @return array the list of applications with their user roles
	 * @throws ClientException
	 */
	public function getApplications(string $admin_user, string $token): array {
		$resp = $this->client->get("https://$this->ocis_host/graph/v1.0/applications", [
			'http_errors' => false,
			'auth' => [
				$admin_user,
				$token
			],
			'verify' => !$this->insecure,
		]);

		if ($resp->getStatusCode() !== 200) {
			throw new ClientException("Failed to fetch roles", __FUNCTION__, $resp);
		}

		$decodedBody = \json_decode($resp->getBody(), true);
		if ($decodedBody === null || !isset($decodedBody['value'])) {
			throw new ClientException("Failed to fetch roles: failed to parse response", __FUNCTION__, $resp);
		}
		// a 'value' key is expected -> return its contents
		return $decodedBody['value'];
	}

	/**
	 * @param string $admin_user
	 * @param string $token
	 * @param string $ocisUserId the oCIS user id to assign the role to
	 * @param string $ocisRoleId the oCIS role id that will be assigned
	 * @param string $ocisResourceId the oCIS app id containing the role
	 * @return array
	 * @throws ClientException
	 */
	public function assignRole(string $admin_user, string $token, string $ocisUserId, string $ocisRoleId, string $ocisResourceId): array {
		$resp = $this->client->post("https://$this->ocis_host/graph/v1.0/users/$ocisUserId/appRoleAssignments", [
			'http_errors' => false,
			'auth' => [
				$admin_user,
				$token
			],
			'json' => [
				'appRoleId' => $ocisRoleId,
				'principalId' => $ocisUserId,
				'resourceId' => $ocisResourceId,
			],
			'verify' => !$this->insecure,
		]);

		if ($resp->getStatusCode() !== 201) {
			throw new ClientException("Failed to assign roles", __FUNCTION__, $resp);
		}

		$decodedBody = \json_decode($resp->getBody(), true);
		if ($decodedBody === null) {
			throw new ClientException("Failed to assign roles: failed to parse response", __FUNCTION__, $resp);
		}
		return $decodedBody;
	}

	/**
	 * Get a list with the information of all personal drives for the user.
	 * The list is expected to have only one item (only one personal drive).
	 *
	 * The $userID and $token will be used for authentication
	 *
	 * @param string $userID
	 * @param string $token
	 * @return array the list of personal drives for the user (the list should
	 * have only one item)
	 * @throws ClientException
	 */
	public function getPersonalDrives(string $userID, string $token): array {
		$resp = $this->client->get("https://$this->ocis_host/graph/v1.0/me/drives", [
			'http_errors' => false,
			'auth' => [
				$userID,
				$token
			],
			'verify' => !$this->insecure,
		]);

		if ($resp->getStatusCode() !== 200) {
			throw new ClientException("Failed to get personal drives", __FUNCTION__, $resp);
		}

		$decodedBody = \json_decode($resp->getBody(), true);
		if ($decodedBody === null) {
			throw new ClientException("Failed to get personal drives: failed to parse response", __FUNCTION__, $resp);
		}

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
	 *
	 * @param string $userID
	 * @param string $token
	 * @param array $driveInfo the drive information as returned by the
	 * "getPersonalDrives" method
	 * @return \Sabre\DAV\Client
	 */
	public function getWebdavClientForDrive(string $userID, string $token, array $driveInfo): \Sabre\DAV\Client {
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
	 *
	 * @param \Sabre\DAV\Client $davClient a SabreDav client as returned by
	 * the "getWebdavClientForDrive" method
	 * @param string $path the OC10 path of the file to be checked in oCIS
	 * @return array the propfind info
	 * @throws DavException
	 */
	public function getOcisFileInfo(\Sabre\DAV\Client $davClient, string $path): array {
		$targetPath = "ownCloud/{$path}";
		// encode the path except for the slashes
		$targetPath = \str_replace('%2F', '/', \rawurlencode($targetPath));
		try {
			$data = $davClient->propFind($targetPath, ['{http://owncloud.org/ns}fileid']);
			return $data;
		} catch (ClientHttpException $ex) {
			$response = $ex->getResponse();
			throw new DavException("Failed to get the file info", __FUNCTION__, $response->getStatus(), $ex);
		}
	}
}
