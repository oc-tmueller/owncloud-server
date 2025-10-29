<?php

namespace OCA\MigrateToInfiniteScale\MigrationState\Exceptions;

/**
 * Exception that happens during the State's "skip" action if you can't
 * skip this state
 */
class UnskippableException extends \Exception {
}
