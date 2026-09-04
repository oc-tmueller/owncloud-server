<?php
// SPDX-License-Identifier: Apache-2.0

namespace OCA\MigrateToInfiniteScale\MigrationState\Exceptions;

/**
 * Commands in the "OCA\MigrateToInfiniteScale\Command" namespace
 * might throw this exception during the state verification step if the
 * verification fails.
 */
class VerifyStateException extends \Exception {
}
