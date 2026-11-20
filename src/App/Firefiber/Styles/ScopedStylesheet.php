<?php

declare(strict_types=1);

namespace Sahil\Firefiber\Styles;

/**
 * Minimal CSS parser that scopes a stylesheet to a component.
 *
 * This is not a regular-expression find/replace: the stylesheet is scanned
 * as a token stream — comments, strings, braces and at-rule preludes are
 * understood — then each complex selector is rewritten so it only matches
 * below the component's `[data-fx-*]` scope attribute:
 *
 *   .btn:hover, .btn > .icon   →   [data-fx-a1b2] .btn:hover, [data-fx-a1b2] .btn > .icon
 *   html, body, :root          →   [data-fx-a1b2]
 *
 * At-rules keep their meaning: `@media`/`@supports`/`@layer`/`@container`/
 * `@scope` recurse into their block, while `@keyframes`/`@font-face`/
 * `@font-feature-values` pass through untouched (their blocks are not
 * selector lists). Anything malformed is preserved verbatim rather than
 * silently dropping or corrupting the author's CSS.
 */
final class ScopedStylesheet
{
    /**
     * At-rules whose block content is a list of style rules to scope.
     */
    private const RECURSIVE_AT_RULES = [
        'media' => true,
        'supports' => true,
        'layer' => true,
        'container' => true,
        'scope' => true,
        'document' => true,
        'starting-style' => true,
    ];

    /**
     * At-rules whose block must never be selector-rewritten.
     */
    private const OPAQUE_AT_RULES = [
        'keyframes' => true,
        'font-face' => true,
        'font-feature-values' => true,
        'page' => true,
        'counter-style' => true,
        'property' => true,
        'position-try' => true,
        'view-transition' => true,
    ];

    /**
     * Scope a stylesheet under `[{attribute}]`.
     *
     * @param string $css       raw component CSS
     * @param string $attribute scope attribute selector, e.g. `[data-fx-abc123]`
     */
    public static function scope(string $css, string $attribute): string
    {
        return self::processRules($css, $attribute);
    }

    /**
     * Parse a sequence of rules. Handles `@at-rule prelude { block }`,
     * `@at-rule prelude;` and `selectors { declarations }` while tracking
     * comments, strings and nested braces.
     */
    private static function processRules(string $css, string $scope): string
    {
        $out = '';
        $length = strlen($css);
        $i = 0;

        while ($i < $length) {
            $rule = self::readRule($css, $i);

            if ($rule === null) {
                // Whitespace or comment trivia between rules.
                if ($css[$i] === '/' && ($css[$i + 1] ?? '') === '*') {
                    $end = strpos($css, '*/', $i + 2);
                    $out .= substr($css, $i, $end === false ? $length - $i : $end + 2 - $i);
                    $i = $end === false ? $length : $end + 2;
                    continue;
                }

                $out .= $css[$i];
                $i++;
                continue;
            }

            [$prelude, $block, $consumed] = $rule;
            $trimmed = trim($prelude);

            if ($trimmed !== '' && $trimmed[0] === '@') {
                $name = strtolower((string) preg_replace('/^@\s*([a-zA-Z-]+).*$/s', '$1', $trimmed));

                if ($block === null) {
                    $out .= $prelude.';';
                } elseif (isset(self::OPAQUE_AT_RULES[$name])) {
                    $out .= $prelude.'{'.$block.'}';
                } elseif (isset(self::RECURSIVE_AT_RULES[$name])) {
                    $out .= $prelude.'{'.self::processRules($block, $scope).'}';
                } else {
                    // Unknown at-rule: treat its block as declarations-free
                    // but still recurse defensively — scoping a real rule
                    // list inside is correct for `@scope`-like constructs,
                    // and non-rule content passes through unchanged anyway.
                    $out .= $prelude.'{'.self::processRules($block, $scope).'}';
                }
            } elseif ($block !== null) {
                $out .= self::scopeSelectorList($prelude, $scope).'{'.$block.'}';
            } else {
                // A bare prelude with no block — invalid CSS; pass through.
                $out .= $prelude.';';
            }

            $i += $consumed;
        }

        return $out;
    }

    /**
     * Read one rule starting at offset $i (which must be a non-trivia
     * character). Returns [prelude, block|null, consumedLength] or null
     * when the character at $i is trivia.
     *
     * @return array{0: string, 1: ?string, 2: int}|null
     */
    private static function readRule(string $css, int $i): ?array
    {
        $length = strlen($css);

        if (self::isTrivia($css, $i)) {
            return null;
        }

        $prelude = '';
        $depth = 0; // ( [ depth inside the prelude

        while ($i < $length) {
            $char = $css[$i];

            if ($char === '/' && ($css[$i + 1] ?? '') === '*') {
                $end = strpos($css, '*/', $i + 2);
                $prelude .= substr($css, $i, $end === false ? $length - $i : $end + 2 - $i);
                $i = $end === false ? $length : $end + 2;
                continue;
            }

            if ($char === '"' || $char === "'") {
                $end = self::skipString($css, $i);
                $prelude .= substr($css, $i, $end - $i);
                $i = $end;
                continue;
            }

            if ($char === '(' || $char === '[') {
                $depth++;
                $prelude .= $char;
                $i++;
                continue;
            }

            if ($char === ')' || $char === ']') {
                $depth = max(0, $depth - 1);
                $prelude .= $char;
                $i++;
                continue;
            }

            if ($char === ';' && $depth === 0) {
                return [$prelude, null, strlen($prelude) + 1];
            }

            if ($char === '{') {
                $block = self::readBlock($css, $i);

                if ($block === null) {
                    // Unbalanced brace: emit the rest verbatim.
                    return [$prelude.substr($css, $i), null, $length - ($i - strlen($prelude))];
                }

                return [$prelude, $block, strlen($prelude) + 1 + strlen($block) + 1];
            }

            if ($char === '}' && $depth === 0) {
                // Stray close: give the block parser its terminator back.
                return [$prelude, null, strlen($prelude)];
            }

            $prelude .= $char;
            $i++;
        }

        return [$prelude, null, strlen($prelude)];
    }

    /**
     * Read a `{ ... }` block starting at the opening brace at $i, honoring
     * strings, comments and nested braces. Returns the inner content or
     * null when the block never closes.
     */
    private static function readBlock(string $css, int $i): ?string
    {
        $length = strlen($css);
        $start = $i + 1;
        $depth = 1;
        $i++;

        while ($i < $length) {
            $char = $css[$i];

            if ($char === '/' && ($css[$i + 1] ?? '') === '*') {
                $end = strpos($css, '*/', $i + 2);
                $i = $end === false ? $length : $end + 2;
                continue;
            }

            if ($char === '"' || $char === "'") {
                $i = self::skipString($css, $i);
                continue;
            }

            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;

                if ($depth === 0) {
                    return substr($css, $start, $i - $start);
                }
            }

            $i++;
        }

        return null;
    }

    /**
     * Return the offset just past the string literal starting at $i.
     */
    private static function skipString(string $css, int $i): int
    {
        $quote = $css[$i];
        $length = strlen($css);
        $i++;

        while ($i < $length) {
            if ($css[$i] === '\\') {
                $i += 2;
                continue;
            }

            if ($css[$i] === $quote) {
                return $i + 1;
            }

            $i++;
        }

        return $length;
    }

    private static function isTrivia(string $css, int $i): bool
    {
        $char = $css[$i];

        if ($char === '/' && ($css[$i + 1] ?? '') === '*') {
            return true;
        }

        return $char === ' ' || $char === "\t" || $char === "\n" || $char === "\r" || $char === "\f";
    }

    /**
     * Split a selector prelude on top-level commas — commas inside
     * parentheses, attribute brackets, or strings do not split — then scope
     * each complex selector.
     */
    private static function scopeSelectorList(string $prelude, string $scope): string
    {
        $selectors = [];
        $current = '';
        $depth = 0;
        $length = strlen($prelude);
        $i = 0;

        while ($i < $length) {
            $char = $prelude[$i];

            if ($char === '"' || $char === "'") {
                $end = self::skipString($prelude, $i);
                $current .= substr($prelude, $i, $end - $i);
                $i = $end;
                continue;
            }

            if ($char === '(' || $char === '[') {
                $depth++;
            } elseif ($char === ')' || $char === ']') {
                $depth = max(0, $depth - 1);
            }

            if ($char === ',' && $depth === 0) {
                $selectors[] = $current;
                $current = '';
                $i++;
                continue;
            }

            $current .= $char;
            $i++;
        }

        $selectors[] = $current;

        return implode(',', array_map(
            static fn (string $selector): string => self::scopeSelector($selector, $scope),
            $selectors,
        ));
    }

    /**
     * Scope one complex selector: document-root selectors become the scope
     * itself; everything else becomes a descendant of it.
     */
    private static function scopeSelector(string $selector, string $scope): string
    {
        $leading = substr($selector, 0, strlen($selector) - strlen(ltrim($selector)));
        $trailing = substr($selector, strlen(rtrim($selector)));
        $trimmed = trim($selector);

        if ($trimmed === '') {
            return $selector;
        }

        // Selector lists that begin with a document-root element mean "the
        // whole document" — inside a component scope that is the component
        // root itself.
        $rootWords = ['html', 'body', ':root'];

        foreach ($rootWords as $rootWord) {
            if (preg_match('/^'.preg_quote($rootWord, '/').'(?=\s|$|>|\+|~|\[|\.|#|:)/i', $trimmed) === 1) {
                $rest = preg_replace('/^'.preg_quote($rootWord, '/').'/i', '', $trimmed, 1);

                return $leading.$scope.($rest !== null && trim($rest) !== '' ? $rest : '').$trailing;
            }
        }

        // `:where([data-fx-x]) sel` keeps combinators meaningful while
        // contributing zero specificity — matching author expectations that
        // component CSS beats unscoped page CSS of equal weight only via
        // the attribute bump. A plain `[data-fx-x] sel` is used instead
        // because zero-specificity scopes would lose to app CSS; scoped
        // styles should behave like the author's own stylesheet for the
        // component's subtree.
        return $leading.$scope.' '.$trimmed.$trailing;
    }
}
