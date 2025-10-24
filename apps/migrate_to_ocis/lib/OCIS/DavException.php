<?php
namespace OCA\MigrateToInfiniteScale\OCIS;

class DavException extends \Exception {
	private string $fromFunction;

	/**
	 * @param string $message the error message
	 * @param string $fromFunction the name of the client's function that
	 * caused this exception
	 * @param int $code
	 * @param \Throwable|null $previous the previous exception, if any
	 */
	public function __construct(string $message, string $fromFunction, int $code = 0, ?\Throwable $previous = null) {
		parent::__construct($message, $code, $previous);
		$this->fromFunction = $fromFunction;
	}

	public function getOriginClientFunction() {
		return $this->fromFunction;
	}
}
