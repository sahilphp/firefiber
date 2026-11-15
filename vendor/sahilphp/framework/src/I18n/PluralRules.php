<?php

declare(strict_types=1);

namespace Sahil\I18n;

/**
 * Locale-aware cardinal plural categories.
 *
 * A pragmatic subset of the CLDR cardinal rules covering the locales the
 * framework ships plus the most common application languages. Categories
 * follow CLDR naming: zero, one, two, few, many, other.
 *
 * Swahili and English genuinely differ: `sw` treats 0 and 1 as the `one`
 * form ("siku moja", "siku 0" read with the singular pattern), while `en`
 * only treats 1 as `one`.
 */
final class PluralRules
{
    /**
     * The CLDR category a count falls into for a locale.
     *
     * @return 'zero'|'one'|'two'|'few'|'many'|'other'
     */
    public static function category(string $locale, int|float $count): string
    {
        $base = strtolower(str_replace('_', '-', $locale));
        $base = explode('-', $base, 2)[0];
        $n = is_float($count) ? $count : (float) $count;

        return match ($base) {
            // Swahili family + French-style rules: 0 and 1 are "one".
            'sw', 'fr', 'pt', 'ff', 'hy', 'kab', 'ak', 'am', 'ln', 'mg', 'wa' => self::zeroOne($n),

            'ar' => self::arabic($n),

            'ru', 'uk', 'be', 'bs', 'hr', 'sr' => self::eastSlavic($n),

            'pl' => self::polish($n),

            'cs', 'sk' => self::czechSlovak($n),

            // One-form languages: no grammatical number.
            'ja', 'zh', 'ko', 'th', 'vi', 'lo', 'ms', 'id', 'tr', 'fa' => 'other',

            // English family and the safe default: exactly 1 is "one".
            default => $n == 1 ? 'one' : 'other',
        };
    }

    /** @return 'one'|'other' */
    private static function zeroOne(float $n): string
    {
        return ($n == 0 || $n == 1) ? 'one' : 'other';
    }

    /** @return 'zero'|'one'|'two'|'few'|'many'|'other' */
    private static function arabic(float $n): string
    {
        if ($n == 0) {
            return 'zero';
        }

        if ($n == 1) {
            return 'one';
        }

        if ($n == 2) {
            return 'two';
        }

        $mod100 = ((int) $n) % 100;

        if ($mod100 >= 3 && $mod100 <= 10) {
            return 'few';
        }

        if ($mod100 >= 11 && $mod100 <= 99) {
            return 'many';
        }

        return 'other';
    }

    /** @return 'one'|'few'|'many'|'other' */
    private static function eastSlavic(float $n): string
    {
        if ((int) $n != $n) {
            return 'other';
        }

        $i = (int) $n;
        $mod10 = $i % 10;
        $mod100 = $i % 100;

        if ($mod10 == 1 && $mod100 != 11) {
            return 'one';
        }

        if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) {
            return 'few';
        }

        return 'many';
    }

    /** @return 'one'|'few'|'many'|'other' */
    private static function polish(float $n): string
    {
        if ((int) $n != $n) {
            return 'other';
        }

        $i = (int) $n;
        $mod10 = $i % 10;
        $mod100 = $i % 100;

        if ($i == 1) {
            return 'one';
        }

        if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) {
            return 'few';
        }

        return 'many';
    }

    /** @return 'one'|'few'|'many'|'other' */
    private static function czechSlovak(float $n): string
    {
        if ($n == 1) {
            return 'one';
        }

        if ($n >= 2 && $n <= 4 && (int) $n == $n) {
            return 'few';
        }

        if ((int) $n != $n) {
            return 'many';
        }

        return 'other';
    }
}
