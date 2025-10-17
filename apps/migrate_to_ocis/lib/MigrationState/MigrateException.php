<?php

namespace OCA\MigrateToInfiniteScale\MigrationState;

class MigrateException extends \Exception {
	private string $advice = '';

	public function setAdvice(string $advice) {
		$this->advice = $advice;
	}

	public function getAdvice(): string {
		return $this->advice;
	}
}
