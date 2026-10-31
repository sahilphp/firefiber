<?php

declare(strict_types=1);

namespace Sahil\Support;

use RuntimeException;

/**
 * Loads environment variables from a `.env` file into an isolated map.
 *
 * Values are kept in this object rather than `$_ENV` / `$_SERVER` so no
 * global mutable state is touched. Parsing rules:
 *
 * - One `KEY=VALUE` pair per line; spaces around the key are trimmed.
 * - `#` starts a comment (full-line or trailing, outside of quotes).
 * - Single- and double-quoted values keep their contents (unescape `\"`
 *   and `\n` inside double quotes).
 * - The literals `true`, `false`, `null` and `empty` map to `true`, `false`,
 *   `null` and empty string respectively when read without quotes.
 * - Lines without `=`, or without a key, are rejected with a descriptive
 *   exception so misconfigurations fail fast at boot.
 */
final class Environment
{
    /** @var array<string, string|null> */
    private array $values = [];

    /**
     * @param array<array-key, scalar|null> $initial starting values (e.g. OS env vars)
     */
    public function __construct(array $initial = [])
    {
        foreach ($initial as $key => $value) {
            if (!is_string($key) || $key === '') {
                throw new RuntimeException('Environment variable keys must be non-empty strings.');
            }

            $this->values[$key] = is_scalar($value) ? (string) $value : null;
        }
    }

    /**
     * Parse a `.env` file and merge its values over the current map.
     *
     * @return static
     */
    public function load(string $path): static
    {
        if (!is_readable($path)) {
            throw new RuntimeException(sprintf('Unable to read environment file [%s].', $path));
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            throw new RuntimeException(sprintf('Unable to read environment file [%s].', $path));
        }

        $this->merge($this->parse($lines, $path), osPrecedent: true);

        return $this;
    }

    /**
     * Read a variable with an optional default. Literal strings such as
     * `true`, `false`, `null` are converted unless a non-null default is
     * supplied with a different scalar type.
     */
    public function get(string $key, bool|int|float|string|null $default = null): mixed
    {
        if (!array_key_exists($key, $this->values)) {
            return $default;
        }

        $value = $this->values[$key];

        if ($value === null) {
            return $default;
        }

        return $this->cast($value);
    }

    /**
     * Override one variable (e.g. testing utilities pinning `APP_ENV`).
     *
     * Values follow the same representation as `.env` input: booleans become
     * `true`/`false`, numbers and strings are stored as text, and null marks
     * the variable as unset. Existing values are replaced.
     */
    public function set(string $key, bool|int|float|string|null $value): void
    {
        $key = trim($key);

        if ($key === '') {
            throw new RuntimeException('Environment variable keys must be non-empty strings.');
        }

        if ($value === null) {
            $this->values[$key] = null;

            return;
        }

        $this->values[$key] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
    }

    /**
     * Whether a variable is defined with a non-null value.
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values)
            && $this->values[$key] !== null
            && $this->values[$key] !== '';
    }

    /**
     * All defined keys, for diagnostics only.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->values);
    }

    /**
     * @param array<string, string|null> $values
     */
    private function merge(array $values, bool $osPrecedent = false): void
    {
        if (!$osPrecedent) {
            $this->values = [...$this->values, ...$values];

            return;
        }

        // Precedence: operating-system environment beats .env values. Values
        // captured at construction (OS env) keep their place; the .env file
        // only fills keys the OS did not provide (or provided as null).
        foreach ($values as $key => $value) {
            if (array_key_exists($key, $this->values) && $this->values[$key] !== null) {
                continue;
            }

            $this->values[$key] = $value;
        }
    }

    /**
     * @param list<string> $lines
     *
     * @return array<string, string|null>
     */
    private function parse(array $lines, string $path): array
    {
        $values = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }

            $position = strpos($line, '=');

            if ($position === false || trim(substr($line, 0, $position)) === '') {
                throw new RuntimeException(sprintf('Malformed line in [%s]: "%s".', $path, $line));
            }

            $key = trim(substr($line, 0, $position));
            $raw = trim(substr($line, $position + 1));
            $raw = $this->stripTrailingComment($raw);

            $values[$key] = $this->unquote($raw);
        }

        return $values;
    }

    /**
     * Remove a trailing `#` comment, respecting quoted value content.
     */
    private function stripTrailingComment(string $raw): string
    {
        $quote = null;
        $length = strlen($raw);

        for ($index = 0; $index < $length; $index++) {
            $character = $raw[$index];

            if (($character === '"' || $character === "'") && $quote === null) {
                $quote = $character;
            } elseif ($character === $quote) {
                $quote = null;
            } elseif ($character === '#' && $quote === null) {
                return trim(substr($raw, 0, $index));
            }
        }

        return trim($raw);
    }

    /**
     * @return string|null null means "not quoted after all", keep raw value
     */
    private function unquote(string $raw): ?string
    {
        $length = strlen($raw);

        if ($length >= 2 && $raw[0] === '"' && $raw[$length - 1] === '"') {
            return str_replace(['\\"', '\\n'], ['"', "\n"], substr($raw, 1, -1));
        }

        if ($length >= 2 && $raw[0] === "'" && $raw[$length - 1] === "'") {
            return substr($raw, 1, -1);
        }

        if ($raw === 'null') {
            return null;
        }

        if ($raw === 'empty') {
            return '';
        }

        return $raw;
    }

    private function cast(string $value): bool|string
    {
        return match (strtolower($value)) {
            'true' => true,
            'false' => false,
            default => $value,
        };
    }
}
