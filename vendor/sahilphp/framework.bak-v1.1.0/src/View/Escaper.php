<?php

declare(strict_types=1);

namespace Sahil\View;

use Stringable;

/**
 * Output escaping.
 *
 * The template language escapes everything by default; these helpers are
 * what the compiled templates call.
 *
 * - {@see html()} is the DEFAULT output path (`{{ ... }}`) and is
 *   HTML-escaping with both quote styles and invalid-UTF-8 substitution.
 * - {@see raw()} is the ONLY unescaped channel (`{!! ... !!}`) — explicit,
 *   visible at the call site, and never applied implicitly.
 * - {@see json()} embeds a structure into a `<script>` block with the
 *   characters that could break out of the surrounding tag escaped.
 *
 * Values whose string form is ambiguous (arrays, arbitrary objects) are
 * REFUSED rather than silently rendered as `Array` or empty: a template bug
 * should be loud.
 */
final class Escaper
{
    private function __construct()
    {
    }

    /**
     * HTML-escape a value for output. `null` renders as nothing — a missing
     * optional value is not an error.
     */
    public static function html(mixed $value): string
    {
        $text = self::stringify($value);

        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Emit a value without escaping. Only reachable through the explicit
     * raw-output syntax; nothing in the engine calls this implicitly.
     */
    public static function raw(mixed $value): string
    {
        return self::stringify($value);
    }

    /**
     * JSON for embedding inside a `<script>` element.
     *
     * `JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT` keep `<`, `>`,
     * `&`, `'` and `"` as `\uXXXX`, so a payload can never close the script
     * element or break out of an attribute.
     */
    public static function json(mixed $value): string
    {
        $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

        $encoded = json_encode($value, $flags);

        if ($encoded === false) {
            throw new ViewRenderException(sprintf(
                'Value of type [%s] cannot be encoded as JSON for @json (unsupported type or invalid UTF-8).',
                get_debug_type($value),
            ));
        }

        return $encoded;
    }

    /**
     * Non-escaping string conversion with the same strictness as html().
     */
    private static function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_bool($value)) {
            return $value ? '1' : '';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if ($value instanceof Stringable) {
            return (string) $value;
        }

        throw new ViewRenderException(sprintf(
            'Cannot output a value of type [%s]; convert it in the controller, loop over it, or embed it with @json(...).',
            get_debug_type($value),
        ));
    }
}
