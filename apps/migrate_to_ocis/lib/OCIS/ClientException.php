<?php
// SPDX-License-Identifier: Apache-2.0
namespace OCA\MigrateToInfiniteScale\OCIS;

use OCP\Http\Client\IResponse;

class ClientException extends \Exception {
	private string $fromFunction;
	private $rawBody;

	/**
	 * @param string $message the error message
	 * @param string $fromFunction the name of the client's function that
	 * caused this exception
	 * @param IResponse $response the response object
	 * @param \Throwable|null $previous the previous exception, if any
	 */
	public function __construct(string $message, string $fromFunction, IResponse $response, ?\Throwable $previous = null) {
		parent::__construct($message, $response->getStatusCode(), $previous);
		$this->rawBody = $response->getBody();
		$this->fromFunction = $fromFunction;
	}

	public function getRawBody() {
		return $this->rawBody;
	}

	public function getOriginClientFunction() {
		return $this->fromFunction;
	}
}
