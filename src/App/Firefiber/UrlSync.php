<?php

declare(strict_types=1);

namespace Sahil\Firefiber;

/**
 * Safe URL query parsing and state serialization utilities.
 */
final class UrlSync
{
    /**
     * Parse a raw query string while preserving duplicate parameters.
     *
     * @return array<string, list<string>>
     */
    public function parse(string $queryString): array
    {
        $queryString = ltrim(trim($queryString), '?');
        $values = [];

        if ($queryString === '') {
            return $values;
        }

        foreach (explode('&', $queryString) as $pair) {
            $separator = strpos($pair, '=');
            $rawKey = $separator === false ? $pair : substr($pair, 0, $separator);
            $rawValue = $separator === false ? '' : substr($pair, $separator + 1);

            if (
                $rawKey !== ''
                && preg_match('/^[A-Za-z0-9._-]{1,80}$/', urldecode($rawKey)) === 1
                && !str_contains($rawValue, "\0")
            ) {
                $values[urldecode($rawKey)][] = urldecode($rawValue);
            }
        }

        return $values;
    }

    /**
     * Serialize component state using an exposed query synchronization map.
     *
     * @param array<string, array{param: string, type: string, multiple: bool}> $configuration
     * @param array<string, mixed> $state
     */
    public function serialize(array $configuration, array $state): string
    {
        $params = [];

        foreach ($configuration as $key => $definition) {
            if (!array_key_exists($key, $state)) {
                continue;
            }

            $value = $state[$key];
            $param = $definition['param'];

            if (!empty($definition['multiple']) && is_array($value)) {
                foreach ($value as $item) {
                    $params[] = urlencode($param).'='.urlencode($this->scalar($item));
                }
            } else {
                $params[] = urlencode($param).'='.urlencode($this->scalar($value));
            }
        }

        return implode('&', $params);
    }

    private function scalar(mixed $value): string
    {
        return is_bool($value) ? ($value ? '1' : '0') : (string) ($value ?? '');
    }
}
