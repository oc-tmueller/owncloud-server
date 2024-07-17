<?php
namespace OCA\MigrateToInfiniteScale\Helper;

use Exception;
use JsonException;
use OCP\Http\Client\IClient;

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
	public function tokenExchange(string $shared_migration_api_key, string $email_address) {
		$resp = $this->client->post("https://$this->ocis_host/satellites/tokenExchange", [
			'headers' => [
				'Authorization' => "Bearer $shared_migration_api_key"
			],
			'json' => [
				'email' => $email_address
			],
			'verify' => !$this->insecure,
		]);
		$json = json_decode($resp->getBody(), true, 512, JSON_THROW_ON_ERROR);
		if (!\is_array($json)) {
			throw new \RuntimeException('Unexpected JSON response');
		}
		return $json['accessToken'];
	}

	public function createUser(string $token, string $email): bool {
		$resp = $this->client->post("https://$this->ocis_host//v1.0/users", [
			'headers' => [
				'Authorization' => "Bearer $token"
			],
			'json' => [
				'mail' => $email
			],
			'verify' => !$this->insecure,
		]);
		if ($resp->getStatusCode() !== 201) {
			return true;
		}
		# TODO: logging? throw something?
		return false;

		/*
		'id' => false,
		'account_enabled' => false,
		'app_role_assignments' => false,
		'display_name' => false,
		'drives' => false,
		'drive' => false,
		'identities' => false,
		'mail' => false,
		'member_of' => false,
		'on_premises_sam_account_name' => false,
		'password_profile' => false,
		'surname' => false,
		'given_name' => false,
		'user_type' => false,
		'preferred_language' => false
		*/
	}
}
