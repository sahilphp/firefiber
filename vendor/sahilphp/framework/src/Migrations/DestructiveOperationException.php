<?php

declare(strict_types=1);

namespace Sahil\Migrations;

use RuntimeException;

/**
 * Thrown when a destructive operation (rollback, seeding, a production
 * schema change) is attempted without deliberate operator intent — neither
 * an interactive confirmation nor the documented force flag.
 *
 * Raised BEFORE anything touches the database.
 */
final class DestructiveOperationException extends RuntimeException
{
    public static function refused(string $operation, string $reason): self
    {
        return new self(sprintf(
            'Refusing [%s]: %s',
            $operation,
            $reason,
        ));
    }

    /**
     * Non-interactive or production context: only the force flag counts.
     */
    public static function requiresForce(string $operation, string $environment): self
    {
        return self::refused($operation, sprintf(
            'this is a destructive operation and the application runs in [%s]; '
            .'re-run with --force once you have a verified backup',
            $environment,
        ));
    }

    public static function needsConfirmation(string $operation): self
    {
        return self::refused($operation, 'the operation was not confirmed; re-run interactively and answer "yes", or pass --force to authorise it');
    }

    /**
     * @param list<string> $irreversible
     */
    public static function irreversible(string $operation, array $irreversible): self
    {
        return self::refused($operation, sprintf(
            'these migrations are forward-only and cannot be reversed: %s. '
            .'Implement down() on them, or pass --force to roll back the reversible migrations and leave these applied',
            implode(', ', $irreversible),
        ));
    }
}
