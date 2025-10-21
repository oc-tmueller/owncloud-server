<?php

namespace OCA\MigrateToInfiniteScale\ConflictLog;

/**
 * Service that can be injected as dependency in order to create new
 * instances of LogFile
 */
class LogService {
	public function newLogFile(): LogFile {
		return new LogFile();
	}
}
