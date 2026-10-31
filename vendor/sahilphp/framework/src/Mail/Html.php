<?php

declare(strict_types=1);

namespace Sahil\Mail;

/**
 * Safe HTML rendering for mail bodies.
 *
 * HTML email cannot be sanitised into "safe HTML" reliably, so the framework
 * does not pretend to: markup passed to {@see Message::html()} is trusted,
 * application-authored markup, and every value interpolated into it must go
 * through {@see escape()}. The helpers here make that the easy path and
 * generate the automatic plain-text/HTML alternatives, which are escaped.
 */
final class Html
{
    /**
     * Escape a value for interpolation into an HTML body.
     */
    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /**
     * Turn a plain-text body into a minimal HTML document.
     *
     * Blank lines separate paragraphs, single line breaks become `<br>`, and
     * the whole text is escaped first — text can never inject markup.
     */
    public static function fromText(string $text): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $text);
        $paragraphs = preg_split('/\n{2,}/', trim($normalized)) ?: [];
        $body = '';

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);

            if ($paragraph === '') {
                continue;
            }

            $escaped = self::escape($paragraph);
            $body .= '<p>'.str_replace("\n", '<br>', $escaped)."</p>\n";
        }

        if ($body === '') {
            $body = "<p></p>\n";
        }

        return "<!doctype html>\n<html>\n<head><meta charset=\"utf-8\"></head>\n<body>\n".$body."</body>\n</html>\n";
    }

    /**
     * Derive a readable plain-text alternative from an HTML body.
     *
     * Links keep their target (`label (https://…)`), block elements become
     * line breaks, scripts and styles are dropped, and entities are decoded.
     */
    public static function toText(string $html): string
    {
        $text = preg_replace('#<(script|style|head)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;

        // Keep the link target next to its label; the label may itself contain
        // markup, which the tag stripping below removes.
        $text = preg_replace(
            '#<a\b[^>]*href=("|\')([^"\']+)\1[^>]*>(.*?)</a>#is',
            '$3 ($2)',
            $text,
        ) ?? $text;

        $text = preg_replace('#<li\b[^>]*>#i', '- ', $text) ?? $text;
        $text = preg_replace('#<(br|/div|/tr|/li)\b[^>]*>#i', "\n", $text) ?? $text;
        $text = preg_replace('#</(p|blockquote|h[1-6])\b[^>]*>#i', "\n\n", $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);

        $lines = array_map(
            static fn (string $line): string => rtrim($line),
            explode("\n", $text),
        );

        $text = implode("\n", $lines);
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }
}
