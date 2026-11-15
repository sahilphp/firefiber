<?php

declare(strict_types=1);

namespace Sahil\Http;

use ArrayIterator;
use Countable;
use InvalidArgumentException;
use IteratorAggregate;

/**
 * A validated HTTP header collection used by both Request and Response.
 *
 * @implements IteratorAggregate<string, list<string>>
 */
final class HeaderBag implements Countable, IteratorAggregate
{
    /**
     * @var array<string, list<string>>
     */
    private array $headers = [];

    /**
     * @param array<string, scalar|array<int, scalar>|null> $headers
     */
    public function __construct(array $headers = [])
    {
        foreach ($headers as $name => $values) {
            $this->set((string) $name, $values);
        }
    }

    /**
     * Assign (replace) a header. Scalar values become one-element sets.
     * Passing `null` removes the header entirely.
     *
     * @param scalar|array<int, mixed>|null $values
     */
    public function set(string $name, mixed $values): void
    {
        self::validateName($name);

        $canonical = self::canonical($name);

        if ($values === null) {
            unset($this->headers[$canonical]);

            return;
        }

        $this->headers[$canonical] = self::normaliseValues($values, $name);
    }

    /**
     * Add to a header without replacing existing values.
     *
     * @param scalar|array<int, mixed>|null $values
     */
    public function add(string $name, mixed $values): void
    {
        self::validateName($name);

        $canonical = self::canonical($name);
        $current = $this->headers[$canonical] ?? [];

        $this->headers[$canonical] = [...$current, ...self::normaliseValues($values, $name)];
    }

    public function has(string $name): bool
    {
        return array_key_exists(self::canonical($name), $this->headers);
    }

    /**
     * All values of a header, or an empty list.
     *
     * @return list<string>
     */
    public function get(string $name): array
    {
        return $this->headers[self::canonical($name)] ?? [];
    }

    /**
     * The first value of a header, or null.
     */
    public function first(string $name): ?string
    {
        return $this->get($name)[0] ?? null;
    }

    /**
     * Comma-joined single string of a header's values, or the default.
     */
    public function line(string $name, ?string $default = null): ?string
    {
        $values = $this->get($name);

        return $values === [] ? $default : implode(', ', $values);
    }

    /**
     * Remove a header.
     */
    public function remove(string $name): void
    {
        unset($this->headers[self::canonical($name)]);
    }

    /**
     * All headers keyed by canonical (capitalised-hyphen) name in order.
     *
     * @return array<string, list<string>>
     */
    public function all(): array
    {
        $sorted = $this->headers;
        ksort($sorted, SORT_STRING);

        return $sorted;
    }

    public function count(): int
    {
        return count($this->headers);
    }

    /**
     * @return ArrayIterator<string, list<string>>
     */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->all());
    }

    /**
     * Cloning a HeaderBag must never share the underlying storage with the
     * original: Response immutability depends on this.
     */
    public function __clone()
    {
        $this->headers = array_map(static fn (array $values): array => [...$values], $this->headers);
    }

    /**
     * Canonical wire form: every word of a token name starts with a
     * capital letter (`Content-Type`, `X-Correlation-Id`).
     */
    public static function canonical(string $name): string
    {
        return implode('-', array_map(ucfirst(...), explode('-', strtolower($name))));
    }

    public static function validateName(string $name): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/', $name) !== 1) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid HTTP header name: names must be ASCII tokens of letters, digits, dashes and underscores, got [%s].',
                    $name,
                ),
            );
        }
    }

    /**
     * @param scalar|array<int, mixed>|null $values
     *
     * @return list<string>
     */
    private static function normaliseValues(mixed $values, ?string $nameOfContext = null): array
    {
        if ($values === null) {
            return [];
        }

        $list = is_array($values) ? $values : [$values];

        $normalised = [];

        foreach ($list as $value) {
            if (!is_scalar($value)) {
                throw new InvalidArgumentException(
                    sprintf('Header values must be scalar; [%s] contains a non-scalar entry.', $nameOfContext ?? 'header'),
                );
            }

            $string = is_float($value)
                ? json_encode($value)
                : (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);

            if (preg_match('/^[\t \x21-\x7E\x80-\xFF]*$/', $string) !== 1) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Invalid value for header [%s]: values must not contain CR, LF, NUL or control characters.',
                        $nameOfContext ?? 'header',
                    ),
                );
            }

            $trimmed = trim($string, " \t");

            $normalised[] = $trimmed;
        }

        return $normalised;
    }
}
