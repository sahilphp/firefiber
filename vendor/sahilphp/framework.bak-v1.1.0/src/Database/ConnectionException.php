<?php

declare(strict_types=1);

namespace Sahil\Database;

use RuntimeException;

/**
 * Thrown when a database CONNECTION cannot be established or its
 * configuration is invalid.
 *
 * The message deliberately NEVER contains the password or the full DSN
 * (which may embed credentials); use `safeContext()` data that was built
 * from redacted parts.
 */
final class ConnectionException extends RuntimeException
{
    /**
     * @param array<string, string> $context redacted extra facts (driver, host…)
     */
    public static function withContext(string $message, array $context = []): self
    {
        $text = $message;

        if ($context !== []) {
            $parts = [];

            foreach ($context as $key => $value) {
                $parts[] = "$key=$value";
            }

            $text .= ' ['.implode(', ', $parts).']';
        }

        return new self($text);
    }
}
