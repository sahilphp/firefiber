<?php

declare(strict_types=1);

namespace Sahil\I18n;

use DateTimeInterface;
use IntlDateFormatter;
use NumberFormatter;

/**
 * Locale-aware formatting on top of PHP's intl extension.
 *
 * Numbers, currency and dates follow the active locale (or an explicitly
 * supplied one — a Swahili-default application is not forced to format
 * Tanzanian shillings; the currency code is always the caller's choice).
 * When ext-intl is unavailable the methods degrade to plain PHP formatting
 * so output is still produced.
 */
final class LocaleFormatter
{
    public function __construct(private readonly Locale $locale)
    {
    }

    /**
     * Decimal formatting honoring the locale's separators
     * (`1 234,56` under `fr`, `1,234.56` under `en`).
     */
    public function number(int|float $value, ?string $locale = null, int $maxDecimals = 2): string
    {
        if (extension_loaded('intl')) {
            $formatter = new NumberFormatter($this->name($locale), NumberFormatter::DECIMAL);
            $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $maxDecimals);

            $formatted = $formatter->format($value);

            if (is_string($formatted)) {
                return $formatted;
            }
        }

        return number_format((float) $value, $maxDecimals);
    }

    /**
     * Currency formatting. The currency is always explicit — chosen by the
     * application, never inferred from the display locale.
     */
    public function currency(int|float $amount, string $currencyCode, ?string $locale = null): string
    {
        $currencyCode = strtoupper(trim($currencyCode));

        if (extension_loaded('intl') && $currencyCode !== '') {
            $formatter = new NumberFormatter($this->name($locale), NumberFormatter::CURRENCY);
            $formatted = $formatter->formatCurrency((float) $amount, $currencyCode);

            if (is_string($formatted)) {
                return $formatted;
            }
        }

        return $currencyCode.' '.number_format((float) $amount, 2);
    }

    /**
     * Date-only formatting in the locale's conventions
     * (`IntlDateFormatter::MEDIUM` by default).
     */
    public function date(DateTimeInterface|int|string $when, int $style = IntlDateFormatter::MEDIUM, ?string $locale = null): string
    {
        return $this->formatDateTime($when, $style, IntlDateFormatter::NONE, 'Y-m-d', $locale);
    }

    /**
     * Time-only formatting in the locale's conventions.
     */
    public function time(DateTimeInterface|int|string $when, int $style = IntlDateFormatter::MEDIUM, ?string $locale = null): string
    {
        return $this->formatDateTime($when, IntlDateFormatter::NONE, $style, 'H:i', $locale);
    }

    /**
     * Combined date-and-time formatting.
     */
    public function datetime(
        DateTimeInterface|int|string $when,
        int $dateStyle = IntlDateFormatter::MEDIUM,
        int $timeStyle = IntlDateFormatter::MEDIUM,
        ?string $locale = null,
    ): string {
        return $this->formatDateTime($when, $dateStyle, $timeStyle, 'Y-m-d H:i', $locale);
    }

    private function formatDateTime(
        DateTimeInterface|int|string $when,
        int $dateStyle,
        int $timeStyle,
        string $fallbackPattern,
        ?string $locale,
    ): string {
        $timestamp = $when instanceof DateTimeInterface
            ? $when->getTimestamp()
            : (is_int($when) ? $when : strtotime($when));

        if ($timestamp === false) {
            return (string) $when;
        }

        if (extension_loaded('intl')) {
            $formatter = new IntlDateFormatter(
                $this->name($locale),
                $dateStyle,
                $timeStyle,
                date_default_timezone_get(),
            );

            $formatted = $formatter->format($timestamp);

            if (is_string($formatted)) {
                return $formatted;
            }
        }

        return date($fallbackPattern, $timestamp);
    }

    /**
     * `en`/`sw_KE`/`en-US` → ICU locale names (`sw` stays `sw`,
     * `sw_KE`/`sw-KE` both become `sw_KE`).
     */
    private function name(?string $locale): string
    {
        return str_replace('-', '_', $locale ?? $this->locale->current());
    }
}
