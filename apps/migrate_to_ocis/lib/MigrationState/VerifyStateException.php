<?php

namespace OCA\MigrateToInfiniteScale\MigrationState;

/**
 * Commands in the "OCA\MigrateToInfiniteScale\Command" namespace
 * might throw this exception during the state verification step if the
 * verification fails.
 */
class VerifyStateException extends \Exception {
}
