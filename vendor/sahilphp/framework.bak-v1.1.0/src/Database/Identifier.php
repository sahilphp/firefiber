<?php

declare(strict_types=1);

namespace Sahil\Database;

use InvalidArgumentException;

/**
 * Validates and splits SQL identifiers ("users", "p.account", aliases).
 *
 * Unquoted identifiers must match `/^[A-Za-z_][A-Za-z0-9_]{0,62}$/` per
 * segment — no pipes, spaces, backticks, semicolons or exotic bytes ever
 * reach the SQL text as an identifier. Driver-level quoting then wraps
 * each validated segment per-dialect, doubling embedded quotes.
 */
final class Identifier
{
    public const SEGMENT_PATTERN = '/^_?[A-Za-z_][A-Za-z0-9_]{0,62}$/';

    /**
     * Longest identifier segment {@see SEGMENT_PATTERN} accepts.
     *
     * Derived constraint/index names are truncated against THIS value so
     * name generation and name validation can never drift apart — MySQL's
     * own limit is 64 characters and the pattern is the stricter bound.
     */
    public const MAX_SEGMENT_LENGTH = 63;

    /**
     * @return list<string> validated segments
     */
    public static function segments(string $identifier): array
    {
        if ($identifier === '') {
            throw new InvalidArgumentException('Identifier must not be empty.');
        }

        $segments = explode('.', $identifier);

        if (count($segments) > 2) {
            throw new InvalidArgumentException(
                sprintf('Identifier [%s] has more than two segments.', $identifier),
            );
        }

        foreach ($segments as $segment) {
            if (preg_match(self::SEGMENT_PATTERN, $segment) !== 1) {
                throw new InvalidArgumentException(
                    sprintf('Unsupported SQL identifier segment [%s].', $segment),
                );
            }
        }

        return $segments;
    }
}
