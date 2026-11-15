<?php

declare(strict_types=1);

namespace Sahil\Mail;

/**
 * Strips credentials out of text that is about to be logged or printed.
 *
 * Transport errors are the one place where third-party libraries hand back
 * fragments of the SMTP dialogue or a DSN. The SMTP transport never logs its
 * transcript, and this class is the second line of defence for messages:
 * `AUTH` payloads, password-like assignments and `user:pass@host` URLs are
 * replaced with `[redacted]`, then the text is collapsed and truncated.
 */
final class Sanitizer
{
    private const MAX_LENGTH = 500;

    private const REDACTED = '[redacted]';

    /**
     * @param list<string> $secrets exact values (usernames, passwords) that
     *                             must never survive in the message
     */
    public static function error(string $message, array $secrets = []): string
    {
        foreach ($secrets as $secret) {
            if ($secret !== '') {
                $message = str_replace($secret, self::REDACTED, $message);
            }
        }

        $patterns = [
            // AUTH PLAIN/LOGIN/XOAUTH2 <base64 or token>
            '/\b(AUTH\s+(?:PLAIN|LOGIN|XOAUTH2)\s+)\S+/i',
            // password=..., token: ..., api_key = ...
            '/\b(password|passwd|pwd|secret|token|api[_-]?key)(\s*[=:]\s*)("[^"]*"|\'[^\']*\'|\S+)/i',
            // scheme://user:pass@host
            '#\b([a-z][a-z0-9+.\-]*://)[^/\s:@]+:[^/\s@]+@#i',
        ];

        $replacements = [
            '$1'.self::REDACTED,
            '$1$2'.self::REDACTED,
            '$1'.self::REDACTED.'@',
        ];

        $clean = preg_replace($patterns, $replacements, $message) ?? '';

        // Never let an exception message span lines or grow unbounded in a log.
        $clean = preg_replace('/\s+/', ' ', $clean) ?? $clean;
        $clean = trim($clean);

        $length = function_exists('mb_strlen') ? mb_strlen($clean, 'UTF-8') : strlen($clean);

        if ($length > self::MAX_LENGTH) {
            $clean = (function_exists('mb_substr')
                ? mb_substr($clean, 0, self::MAX_LENGTH, 'UTF-8')
                : substr($clean, 0, self::MAX_LENGTH)).'…';
        }

        return $clean;
    }
}
