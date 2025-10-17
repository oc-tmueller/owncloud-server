<?php

namespace OCA\MigrateToInfiniteScale\ConflictLog;

class LogService {
	public function newLogFile(): LogFile {
		return new LogFile();
	}
}
