<?php

declare(strict_types=1);

namespace Sahil\Migrations;

use RuntimeException;

/**
 * Thrown when `down()` is invoked on a migration that never implemented it.
 *
 * Reversibility is detected automatically ({@see Migration::isReversible()}),
 * so a forward-only migration can never be rolled back by accident.
 */
final class IrreversibleMigrationException extends RuntimeException
{
    public function __construct(string $migration)
    {
        parent::__construct(sprintf(
            'Migration [%s] does not implement down() and is not reversible.',
            $migration,
        ));
    }
}
