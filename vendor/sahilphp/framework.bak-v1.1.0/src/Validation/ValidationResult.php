<?php

declare(strict_types=1);

namespace Sahil\Validation;

/**
 * The outcome of one validation run. Deterministic and immutable.
 *
 * Two data sets are always kept apart — this is the API-level expression
 * of "never trust input merely because it was validated once":
 *
 *   - {@see errors()}  → what failed, keyed by dotted path,
 *   - {@see validated()} → ONLY the fields that had rules AND passed,
 *     rebuilt into nested arrays. Anything absent here was unvalidated
 *     or failed; callers must decide explicitly what to persist.
 *
 * There is no "accepted input with mixed status" accessor — passing
 * fields with holes are not merged back into raw input.
 */
final class ValidationResult
{
    /**
     * @param array<string, list<string>> $errors dot-path → messages (in rule order)
     * @param array<string, mixed> $validated flat dot-paths → validated values
     */
    public function __construct(private readonly array $errors, private readonly array $validated)
    {
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    /**
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @return list<string>
     */
    public function errorLines(): array
    {
        $lines = [];

        foreach ($this->errors as $messages) {
            array_push($lines, ...$messages);
        }

        return $lines;
    }

    /**
     * Retrieve an exception carrying this result for controllers that
     * prefer exceptions (kernel turns it into a 500 unless caught).
     */
    public function toException(): ValidationException
    {
        return new ValidationException($this);
    }

    /**
     * Nested, validated-only data shape (paths become arrays).
     *
     * @return array<string, mixed>
     */
    public function data(): array
    {
        $tree = [];

        foreach ($this->validated as $path => $value) {
            self::assign($tree, explode('.', $path), $value);
        }

        return $tree;
    }

    /**
     * @param array<string, mixed> $target
     * @param list<string> $segments
     */
    private static function assign(array &$target, array $segments, mixed $value): void
    {
        $head = array_shift($segments);

        if ($head === null) {
            return;
        }

        if ($segments === []) {
            $target[$head] = $value;

            return;
        }

        if (!is_array($target[$head] ?? null)) {
            $target[$head] = [];
        }

        $nested = $target[$head];
        self::assign($nested, $segments, $value);
        $target[$head] = $nested;
    }
}
