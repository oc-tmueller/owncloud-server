<?php
namespace OCA\MigrateToInfiniteScale\Helper;

use Exception;
use JsonException;
use OCP\Http\Client\IClient;
use OCP\IUser;

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
	public function createUser(string $token, IUser $user): bool {
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
			return true;
		}
		$errorCode = $body['error']['code'] ?? '';
		if ($errorCode === 'nameAlreadyExists' && $resp->getStatusCode() === 409) {
			return false;
		}

		throw new \RuntimeException("Failed to create user! Error: $errorCode");
	}
}
