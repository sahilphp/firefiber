<?php

declare(strict_types=1);

namespace Sahil\I18n;

use Closure;

/**
 * Looks messages up in the active locale, then the configured fallback.
 *
 * Resolution for `group.path.to.key`:
 *   1. `lang/{locale}/{group}.php` in every registered search path
 *      (application first, framework catalog last — apps override
 *      framework messages key-by-key),
 *   2. the same key in `app.fallback_locale` when different,
 *   3. the literal key — a missing translation degrades to the key, is
 *      reported once through the missing-key hook, and never throws.
 */
final class Translator implements TranslatorContract
{
    /** @var array<string, true> keys already reported missing */
    private array $reportedMissing = [];

    /**
     * @param Closure(string, string): void|null $reportMissing receives the
     *        missing key and the locale it was requested in (wired to the
     *        application's log by the container binding when available).
     */
    public function __construct(
        private readonly LangLoader $loader,
        private readonly Locale $locale,
        private readonly ?Closure $reportMissing = null,
    ) {
    }

    public function get(string $key, array $replace = [], ?string $locale = null): string
    {
        $line = $this->line($key, $locale);

        if ($line === null) {
            return $this->missing($key, $locale);
        }

        return $this->replace(is_array($line) ? implode(' ', $this->flatten($line)) : $line, $replace);
    }

    public function choice(string $key, int|float $count, array $replace = [], ?string $locale = null): string
    {
        $line = $this->line($key, $locale);

        if ($line === null) {
            return $this->missing($key, $locale);
        }

        $category = PluralRules::category($locale ?? $this->locale->current(), $count);
        $chosen = $this->form($line, $count, $category);

        return $this->replace($chosen, ['count' => $count] + $replace);
    }

    public function has(string $key, ?string $locale = null): bool
    {
        return $this->line($key, $locale) !== null;
    }

    public function locale(): string
    {
        return $this->locale->current();
    }

    /**
     * Resolve a dotted key to its stored line in the active then fallback
     * locale. The first segment is the file/group; `foo` alone reads
     * `messages.foo`. An array node is a valid line (plural form maps or
     * nested message groups rendered as text).
     */
    private function line(string $key, ?string $locale): string|array|null
    {
        $key = trim($key);

        if ($key === '') {
            return null;
        }

        [$group, $path] = str_contains($key, '.')
            ? explode('.', $key, 2)
            : ['messages', $key];

        foreach ($this->candidates($locale) as $candidate) {
            $lines = $this->loader->lines($candidate, $group);

            if ($lines === []) {
                continue;
            }

            $node = $this->walk($lines, $path);

            if (is_string($node) || is_array($node)) {
                return $node;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function candidates(?string $locale): array
    {
        $active = $locale ?? $this->locale->current();
        $fallback = $this->locale->fallback();

        return $active === $fallback ? [$active] : [$active, $fallback];
    }

    /**
     * @param array<string, mixed> $lines
     */
    private function walk(array $lines, string $path): mixed
    {
        $node = $lines;

        foreach (explode('.', $path) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return null;
            }

            $node = $node[$segment];
        }

        return $node;
    }

    /**
     * Pick the right plural form out of a stored line:
     *   - `['one' => ..., 'other' => ...]` keyed by CLDR category,
     *   - `'zero|one|other'` style pipes, optionally with `{n}` / `[a,b]` /
     *     `[a,*]` explicit selectors evaluated first.
     */
    private function form(string|array $line, int|float $count, string $category): string
    {
        if (is_array($line)) {
            $form = $line[$category] ?? $line['other'] ?? reset($line);

            return is_string($form) ? $form : implode(' ', $this->flatten($line));
        }

        if (!str_contains($line, '|')) {
            return $line;
        }

        $segments = array_map(trim(...), explode('|', $line));
        $selectorless = [];

        foreach ($segments as $segment) {
            if (preg_match('/^\{(\d+(?:\.\d+)?)\}\s*(.*)$/s', $segment, $m) === 1) {
                if ($count == (float) $m[1]) {
                    return trim($m[2]);
                }

                continue;
            }

            if (preg_match('/^\[([0-9*]+)(?:,([0-9*]+))?\]\s*(.*)$/s', $segment, $m) === 1) {
                $from = $m[1] === '*' ? -INF : (float) $m[1];
                $to = !isset($m[2]) ? $from : ($m[2] === '*' ? INF : (float) $m[2]);

                if ($count >= $from && $count <= $to) {
                    return trim($m[3]);
                }

                continue;
            }

            $selectorless[] = $segment;
        }

        // Selectorless segments fill the CLDR slots in order: the first is
        // the "one" form, the second the "other" form — `A|B` reads as
        // singular|plural for two-form locales.
        if ($selectorless === []) {
            return $line;
        }

        if ($category === 'one') {
            return $selectorless[0];
        }

        return $selectorless[1] ?? $selectorless[0];
    }

    private function replace(string $line, array $replace): string
    {
        foreach ($replace as $name => $value) {
            $line = str_replace(':'.$name, (string) $value, $line);
        }

        return $line;
    }

    /** @return list<string> */
    private function flatten(array $tree): array
    {
        $flat = [];

        array_walk_recursive($tree, static function (mixed $leaf) use (&$flat): void {
            if (is_scalar($leaf)) {
                $flat[] = (string) $leaf;
            }
        });

        return $flat;
    }

    private function missing(string $key, ?string $locale): string
    {
        $requested = $locale ?? $this->locale->current();
        $signature = $requested."\x00".$key;

        if (!isset($this->reportedMissing[$signature])) {
            $this->reportedMissing[$signature] = true;

            if ($this->reportMissing !== null) {
                ($this->reportMissing)($key, $requested);
            }
        }

        return $key;
    }
}
