<?php
namespace OCA\MigrateToInfiniteScale\Helper;

use Exception;
use JsonException;
use OCP\Http\Client\IClient;
use OCP\IUser;
use OCP\IGroup;

class OCISClient {
	private IClient $client;
	private string $ocis_host;
	private bool $insecure;

	public function __construct(IClient $client, string $ocis_host, bool $insecure) {
		$this->client = $client;
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
}
