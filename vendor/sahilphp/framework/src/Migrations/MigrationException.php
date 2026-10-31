<?php

declare(strict_types=1);

namespace Sahil\Migrations;

use RuntimeException;
use Throwable;

/**
 * Thrown when a migration cannot be discovered, loaded or applied.
 *
 * The message always names the offending migration and keeps the driver's
 * original message, so a failure is reported accurately rather than
 * summarized away. The previous throwable is always chained.
 */
final class MigrationException extends RuntimeException
{
    public static function forMigration(string $name, string $reason, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('Migration [%s] failed: %s', $name, $reason),
            0,
            $previous,
        );
    }

    /**
     * A migration that could not be loaded from disk.
     */
    public static function unloadable(string $path, string $reason): self
    {
        return new self(sprintf('Migration file [%s] could not be loaded: %s', $path, $reason));
    }

    public static function notFound(string $name): self
    {
        return new self(sprintf(
            'Migration [%s] is recorded as applied but no matching file exists; restore the file (or pass --force to forget the record deliberately).',
            $name,
        ));
    }
}
