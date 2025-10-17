<?php

namespace OCA\MigrateToInfiniteScale\ConflictLog;

class LogFile {
	/** @var false|resource */
	private $handle;
	private string $path;

	public function __destruct() {
		fclose($this->handle);
	}

	public function open(string $path): bool {
		$this->path = $path;
		$this->handle = fopen($path, 'xb');
		return $this->handle !== false;
	}

	public function putCSV(array $fields): bool {
		$return = fputcsv($this->handle, $fields);
		fflush($this->handle);
		return $return !== false;
	}

	public function getName(): string {
		return $this->path;
	}
}
