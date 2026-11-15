<?php

declare(strict_types=1);

namespace Sahil\I18n;

/**
 * Matches an `Accept-Language` header against the application's supported
 * locales.
 *
 * Entries are ordered by their `q` weight (descending, stable); each
 * requested tag is accepted exactly or by base-language fallback
 * (`en-GB` satisfies a supported `en`, and a requested `en` satisfies a
 * supported `en-GB`). Anything else is ignored — the result is always
 * either a supported locale or null.
 */
final class LocaleNegotiator
{
    /**
     * @param list<string> $supported
     */
    public function negotiate(?string $acceptLanguage, array $supported): ?string
    {
        if ($acceptLanguage === null || trim($acceptLanguage) === '' || $supported === []) {
            return null;
        }

        $supportedMap = [];

        foreach ($supported as $locale) {
            $supportedMap[self::canonical($locale)] = $locale;
        }

        foreach ($this->ranked($acceptLanguage) as [$tag, $_quality]) {
            $canonical = self::canonical($tag);

            if ($canonical === '' || $canonical === '*') {
                continue;
            }

            if (isset($supportedMap[$canonical])) {
                return $supportedMap[$canonical];
            }

            // `en-GB` requested, only `en` supported (or vice versa).
            $base = explode('-', $canonical, 2)[0];

            if (isset($supportedMap[$base])) {
                return $supportedMap[$base];
            }

            foreach ($supportedMap as $candidate => $locale) {
                if (str_starts_with($candidate, $base.'-')) {
                    return $locale;
                }
            }
        }

        return null;
    }

    /**
     * Parse the header into `[tag, quality]` pairs ordered best-first.
     *
     * @return list<array{0: string, 1: float}>
     */
    private function ranked(string $header): array
    {
        $ranked = [];
        $order = 0;

        foreach (explode(',', $header) as $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            $tag = $part;
            $quality = 1.0;

            if (str_contains($part, ';')) {
                [$tag, $params] = array_map(trim(...), explode(';', $part, 2));

                foreach (explode(';', $params) as $param) {
                    $param = trim($param);

                    if (str_starts_with($param, 'q=')) {
                        $quality = max(0.0, min(1.0, (float) substr($param, 2)));
                    }
                }
            }

            if ($quality <= 0.0) {
                continue; // explicitly refused
            }

            $ranked[] = [trim($tag), $quality, $order++];
        }

        usort($ranked, static fn (array $a, array $b): int => $b[1] <=> $a[1] ?: $a[2] <=> $b[2]);

        return array_map(static fn (array $entry): array => [$entry[0], $entry[1]], $ranked);
    }

    private static function canonical(string $tag): string
    {
        return str_replace('_', '-', strtolower(trim($tag)));
    }
}
