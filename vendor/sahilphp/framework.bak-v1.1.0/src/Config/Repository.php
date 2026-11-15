<?php

declare(strict_types=1);

namespace Sahil\Config;

use ArrayAccess;

/**
 * Dot-notation configuration repository.
 *
 * @implements ArrayAccess<array-key, mixed>
 */
final class Repository implements ArrayAccess
{
    /** @var array<string, mixed> */
    private array $items = [];

    /**
     * @param array<string, mixed> $items initial configuration tree
     */
    public function __construct(array $items = [])
    {
        $this->items = $items;
    }

    /**
     * Read a value by dotted key path with an optional default.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $segments = $this->split($key);
        $current = $this->items;

        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return $default;
            }

            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * Typed reads: a missing key or a mismatching type raises a
     * {@see ConfigurationException} with the dotted key in the message —
     * configuration problems surface as configuration errors.
     *
     * @param non-empty-string $key
     */
    public function getBool(string $key): bool
    {
        $value = $this->get($key);

        if (!is_bool($value)) {
            throw new ConfigurationException(
                sprintf('Configuration key [%s] must be a boolean, got %s.', $key, get_debug_type($value)),
            );
        }

        return $value;
    }

    /**
     * @param non-empty-string $key
     */
    public function getInt(string $key): int
    {
        $value = $this->get($key);

        if (!is_int($value)) {
            throw new ConfigurationException(
                sprintf('Configuration key [%s] must be an integer, got %s.', $key, get_debug_type($value)),
            );
        }

        return $value;
    }

    /**
     * @param non-empty-string $key
     */
    public function getString(string $key): string
    {
        $value = $this->get($key);

        if (!is_string($value)) {
            throw new ConfigurationException(
                sprintf('Configuration key [%s] must be a string, got %s.', $key, get_debug_type($value)),
            );
        }

        return $value;
    }

    /**
     * @return list<mixed>
     */
    public function getList(string $key): array
    {
        $value = $this->get($key);

        if (!is_array($value) || array_is_list($value) === false) {
            throw new ConfigurationException(
                sprintf('Configuration key [%s] must be a list, got %s.', $key, get_debug_type($value)),
            );
        }

        return $value;
    }

    /**
     * Assign a value at a dotted key path, creating intermediate arrays.
     */
    public function set(string $key, mixed $value): void
    {
        $segments = $this->split($key);
        $leave = array_pop($segments);
        $cursor = & $this->items;

        foreach ($segments as $segment) {
            if (!is_array($cursor[$segment] ?? null)) {
                $cursor = (array) $cursor;
                $cursor[$segment] = [];
            }

            $cursor = & $cursor[$segment];
        }

        $cursor[$leave] = $value;
        unset($cursor);
    }

    /**
     * Whether a dotted key path resolves to a stored entry. A stored null
     * counts as present; only absent keys return false.
     */
    public function has(string $key): bool
    {
        $segments = $this->split($key);
        $current = $this->items;

        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return false;
            }

            $current = $current[$segment];
        }

        return true;
    }

    /**
     * Merge another configuration tree over the current one (deep merge,
     * array values from the incoming tree win on scalar conflicts).
     *
     * @param array<array-key, mixed> $items
     */
    public function merge(array $items): void
    {
        $this->items = $this->mergeDeep($this->items, $items);
    }

    /**
     * Full configuration tree. Intended for snapshotting; *do not* mutate
     * the returned value expecting the repository to change.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * Replace the whole configuration tree.
     *
     * @param array<string, mixed> $items
     */
    public function replace(array $items): void
    {
        $this->items = $items;
    }

    public function offsetExists(mixed $offset): bool
    {
        return is_string($offset) && $this->has($offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->get((string) $offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->set((string) $offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        // Removal is ambiguous for dot paths; explicit reject keeps the
        // data model predictable.
        throw new \LogicException(
            'Unsetting configuration keys is not supported. Use set() to overwrite or replace() to rebuild.',
        );
    }

    /**
     * @return list<string> key segments
     */
    private function split(string $key): array
    {
        $segments = explode('.', trim($key, '.'));

        if (in_array('', $segments, true)) {
            throw new \InvalidArgumentException(
                sprintf('Malformed configuration key [%s]; empty path segments are not allowed.', $key),
            );
        }

        return $segments;
    }

    /**
     * @param array<array-key, mixed> $base
     * @param array<array-key, mixed> $override
     *
     * @return array<array-key, mixed>
     */
    private function mergeDeep(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && is_array($base[$key] ?? null)) {
                $base[$key] = $this->mergeDeep($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }
}
