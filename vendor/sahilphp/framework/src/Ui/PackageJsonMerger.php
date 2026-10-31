<?php

declare(strict_types=1);

namespace Sahil\Ui;

/**
 * Non-destructive JSON merging for package manifests.
 *
 * Existing values always win. The profile only adds missing scripts and
 * dependencies, so a user's pinned version, custom script or extra metadata
 * survives `ui:install --merge`.
 */
final class PackageJsonMerger
{
    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $desired
     *
     * @return array<string, mixed>
     */
    public static function merge(array $existing, array $desired): array
    {
        $merged = self::mergeMaps($desired, $existing);

        foreach (['scripts', 'dependencies', 'devDependencies', 'peerDependencies', 'optionalDependencies'] as $key) {
            if (isset($merged[$key]) && is_array($merged[$key])) {
                ksort($merged[$key]);
            }
        }

        return $merged;
    }

    /**
     * @param array<string, mixed> $desired
     * @param array<string, mixed> $existing
     *
     * @return array<string, mixed>
     */
    private static function mergeMaps(array $desired, array $existing): array
    {
        $result = $desired;

        foreach ($existing as $key => $value) {
            $key = (string) $key;

            if (
                isset($result[$key])
                && is_array($result[$key])
                && is_array($value)
                && self::isMap($result[$key])
                && self::isMap($value)
            ) {
                /** @var array<string, mixed> $current */
                $current = $result[$key];

                /** @var array<string, mixed> $incoming */
                $incoming = $value;

                $result[$key] = self::mergeMaps($current, $incoming);

                continue;
            }

            // Lists (keywords, files, ...) and scalars are replaced by the
            // user's value as-is. Merge never truncates existing data.
            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * Semantic equality: key order and whitespace do not matter.
     *
     * @param array<mixed> $left
     * @param array<mixed> $right
     */
    public static function equals(array $left, array $right): bool
    {
        return self::normalize($left) === self::normalize($right);
    }

    /**
     * @param array<mixed> $value
     *
     * @return array<mixed>
     */
    private static function normalize(array $value): array
    {
        $isList = array_is_list($value);
        $result = [];

        foreach ($value as $key => $item) {
            $result[$key] = is_array($item) ? self::normalize($item) : $item;
        }

        if (!$isList) {
            ksort($result);
        }

        return $result;
    }

    /**
     * @param array<mixed> $value
     */
    private static function isMap(array $value): bool
    {
        return !array_is_list($value);
    }
}
