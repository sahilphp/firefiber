<?php

declare(strict_types=1);

namespace Sahil\Orm;

use RuntimeException;

/**
 * Thrown by `Model::fill()` when the caller tries to set an attribute
 * that is neither fillable nor unguarded — mass-assignment protection
 * fails LOUDLY, never by silently dropping the key.
 */
final class MassAssignmentException extends RuntimeException
{
    /**
     * @param list<string> $rejected
     */
    public static function forKeys(array $rejected, string $model): self
    {
        return new self(sprintf(
            'Mass assignment blocked on [%s] for attribute(s): %s — add them to $fillable or unset.',
            $model,
            implode(', ', $rejected),
        ));
    }
}
