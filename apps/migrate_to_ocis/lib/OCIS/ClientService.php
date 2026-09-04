<?php
// SPDX-License-Identifier: Apache-2.0

namespace OCA\MigrateToInfiniteScale\OCIS;

use OCP\Http\Client\IClientService;
use OCP\Http\Client\IWebDavClientService;
use OCP\IConfig;
use OCA\MigrateToInfiniteScale\OCIS\Client;

/**
 * ClientService is a service to crate oCIS clients.
 * This service can be injected as dependency into any class.
 */
class ClientService {
	/** @var IClientService */
	private IClientService $httpClientService;
	/** @var IWebDavClientService */
	private IWebDavClientService $webdavClientService;
	/** @var IConfig */
	private IConfig $config;

	public function __construct(
		IClientService $httpClientService,
		IWebDavClientService $webdavClientService,
		IConfig $config
	) {
		$this->httpClientService = $httpClientService;
		$this->webdavClientService = $webdavClientService;
		$this->config = $config;
	}

	/**
	 * Creates a new oCIS client.
	 * This method relies on some config values (specially the ocis host)
	 * that should have been setup at some point, otherwise the returned
	 * oCIS client might not work properly.
	 */
	public function newOCISClient(): Client {
		$ocis_host = $this->config->getAppValue('migrate_to_ocis', 'ocis_host', '');
		$ocis_host_insecure = $this->config->getAppValue('migrate_to_ocis', 'ocis_host_insecure', false);
		return new Client($this->httpClientService->newClient(), $this->webdavClientService, $ocis_host, $ocis_host_insecure);
	}
}
