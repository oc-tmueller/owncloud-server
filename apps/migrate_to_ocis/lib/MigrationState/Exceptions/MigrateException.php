<?php

namespace OCA\MigrateToInfiniteScale\MigrationState\Exceptions;

/**
 * Exception that happens during the State's "migrate" action
 */
class MigrateException extends \Exception {
	private string $advice = '';

	public function setAdvice(string $advice) {
		$this->advice = $advice;
	}

	public function getAdvice(): string {
		return $this->advice;
	}
}
