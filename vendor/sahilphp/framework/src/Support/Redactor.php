<?php

declare(strict_types=1);

namespace Sahil\Support;

/**
 * Examines dotted keys/values and redacts anything that could be secret.
 *
 * Policy (documented so operators can rely on it):
 * - Any `Repository` key PATH segment matching
 *   `password|passwd|secret|token|credential|api[-_]?key|private|signing|encryption`
 *   makes the whole subtree sensitive → printed as `[redacted]`.
 * - The full key shape (path names) is always visible; values never are.
 * - The redactor maps the config tree to dotted `key => display value`
 *   pairs for CLI output and log context. Values are scalars, JSON
 *   encoded when needed; no object internals ever reach the surface.
 */
final class Redactor
{
    private const SENSITIVE = '/(?:password|passwd|secret|token|credential|api.?key|private|signing|encryption)/i';

    private const REDACTED = '[redacted]';

    /**
     * Is this dotted key (or any of its path segments) sensitive?
     */
    public static function isSensitive(string $dottedKey): bool
    {
        return preg_match(self::SENSITIVE, $dottedKey) === 1;
    }

    /**
     * Flatten a configuration-like tree into dotted keys. Sensitive values
     * show up as `[redacted]`; everything else is a scalar or JSON.
     *
     * @param array<array-key, mixed> $tree
     *
     * @return array<string, string|int|float|bool|null>
     */
    public static function flattenSafely(array $tree, string $prefix = ''): array
    {
        $flat = [];

        foreach ($tree as $key => $value) {
            $dotted = ($prefix === '' ? '' : $prefix.'.').(string) $key;

            if (is_array($value)) {
                if (self::isSensitive($dotted)) {
                    $flat[$dotted] = self::REDACTED;

                    continue;
                }

                $flat = [...$flat, ...self::flattenSafely($value, $dotted)];

                continue;
            }

            $flat[$dotted] = self::isSensitive($dotted)
                ? self::REDACTED
                : (is_scalar($value) || $value === null
                    ? $value
                    : (json_encode($value, JSON_THROW_ON_ERROR) ?: '?'));
        }

        return $flat;
    }
}
