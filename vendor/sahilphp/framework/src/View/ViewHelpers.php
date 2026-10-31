<?php

declare(strict_types=1);

namespace Sahil\View;

use Countable;
use Stringable;
use Traversable;

/**
 * The ONLY functions a template expression may call.
 *
 * The expression language maps its whitelist onto these methods, so a
 * template can never reach an arbitrary PHP function: there is no
 * "call anything" path, and every helper here behaves predictably for the
 * loose data that comes out of a database or a request.
 *
 * Semantics are deliberately total — these never throw on a surprising
 * type, so a template cannot blow up on an unset value:
 *
 * | Template call | Behaviour |
 * |---------------|-----------|
 * | `count(x)` | `null` → 0, array/`Countable` → count, string → length, other → 1 |
 * | `empty(x)` | `null`, `''`, `[]`, `false` are empty; **0 and '0' are not** |
 * | `upper(x)` / `lower(x)` | multibyte-aware when `mbstring` is available |
 * | `trim(x)` | trims whitespace |
 * | `join(glue, items)` | implodes any iterable of scalars |
 * | `first(x)` / `last(x)` | first/last element of an iterable |
 * | `asset(path)` | public URL for a file under `public/` (see RenderContext) |
 */
final class ViewHelpers
{
    private function __construct()
    {
    }

    public static function count(mixed $value): int
    {
        if ($value === null) {
            return 0;
        }

        if (is_array($value) || $value instanceof Countable) {
            return count($value);
        }

        if ($value instanceof Traversable) {
            $total = 0;

            foreach ($value as $_) {
                ++$total;
            }

            return $total;
        }

        if (is_string($value)) {
            return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        }

        return 1;
    }

    /**
     * `true` when a value has no content. Note that `0` and `'0'` are
     * considered present, unlike PHP's `empty()`.
     */
    public static function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [] || $value === false;
    }

    public static function upper(string|Stringable $value): string
    {
        $text = (string) $value;

        return function_exists('mb_strtoupper') ? mb_strtoupper($text, 'UTF-8') : strtoupper($text);
    }

    public static function lower(string|Stringable $value): string
    {
        $text = (string) $value;

        return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    }

    public static function trim(string|Stringable $value): string
    {
        return trim((string) $value);
    }

    public static function join(mixed $glue, mixed $items): string
    {
        $pieces = [];

        foreach (self::iterate($items, 'join()') as $item) {
            $pieces[] = match (true) {
                $item === null => '',
                is_scalar($item) => (string) $item,
                $item instanceof Stringable => (string) $item,
                default => throw new ViewRenderException(sprintf(
                    'join() cannot stringify an element of type [%s].',
                    get_debug_type($item),
                )),
            };
        }

        return implode(is_scalar($glue) || $glue instanceof Stringable ? (string) $glue : '', $pieces);
    }

    public static function first(mixed $items): mixed
    {
        foreach (self::iterate($items, 'first()') as $item) {
            return $item;
        }

        return null;
    }

    public static function last(mixed $items): mixed
    {
        $last = null;

        foreach (self::iterate($items, 'last()') as $item) {
            $last = $item;
        }

        return $last;
    }

    /**
     * Membership test for the `in` operator.
     *
     * Arrays are searched with loose comparison (database values arrive as
     * strings, so `1 in [1, 2]` should match whether the 1 is int or string);
     * strings use a substring test; `null` is never a member of anything.
     */
    public static function contains(mixed $needle, mixed $haystack): bool
    {
        if ($haystack === null || $needle === null) {
            return false;
        }

        if (is_array($haystack)) {
            return in_array($needle, $haystack, false);
        }

        if ($haystack instanceof Traversable) {
            foreach ($haystack as $item) {
                if ($item === $needle) {
                    return true;
                }
            }

            return false;
        }

        if (is_scalar($haystack) || $haystack instanceof Stringable) {
            return is_scalar($needle) || $needle instanceof Stringable
                ? str_contains((string) $haystack, (string) $needle)
                : false;
        }

        return false;
    }

    /**
     * Every `@foreach` target goes through here.
     *
     * `null` iterates zero times (an unset list is empty, not a bug), while
     * a scalar is REFUSED — looping over a string is always a mistake and
     * silently doing nothing would hide it.
     *
     * @return iterable<int|string, mixed>
     */
    public static function iterate(mixed $value, string $context = '@foreach'): iterable
    {
        if ($value === null) {
            return [];
        }

        if (is_array($value) || $value instanceof Traversable) {
            return $value;
        }

        throw new ViewRenderException(sprintf(
            '%s expects an array or iterable, got [%s].',
            $context,
            get_debug_type($value),
        ));
    }
}
