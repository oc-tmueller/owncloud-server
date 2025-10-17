<?php

namespace OCA\MigrateToInfiniteScale\OCIS;

use OCP\Http\Client\IClientService;
use OCP\Http\Client\IWebDavClientService;
use OCP\IConfig;
use OCA\MigrateToInfiniteScale\OCIS\Client;

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

	public function newOCISClient() {
		$ocis_host = $this->config->getAppValue('migrate_to_ocis', 'ocis_host', null);
		$ocis_host_insecure = $this->config->getAppValue('migrate_to_ocis', 'ocis_host_insecure', false);
		return new Client($this->httpClientService->newClient(), $this->webdavClientService, $ocis_host, $ocis_host_insecure);
	}
}
