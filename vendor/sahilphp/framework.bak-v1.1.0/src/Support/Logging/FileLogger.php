<?php

declare(strict_types=1);

namespace Sahil\Support\Logging;

use RuntimeException;
use Sahil\Support\Redactor;

/**
 * File logger writing structured JSON lines (one event per line).
 *
 * Behavior:
 * - Writes to `$path` with `FILE_APPEND` and a `LOCK_EX` guard.
 * - Lines carry: timestamp (ISO-8601 UTC), level, message, context.
 * - Context values pass through {@see Redactor::flattenSafely()} — logs
 *   can never printed secret material by accident.
 * - Events below the configured threshold (by severity order) are skipped.
 * - Known PSR-3-ish levels only; unknown levels fall back to `unknown`
 *   severity ordering at the top so misuse is loud, not invisible.
 *
 * Fail-fast philosophy: an unwritable log sink throws — a broken logging
 * setup must not silently swallow diagnostics.
 */
final class FileLogger implements StructuredLoggerContract
{
    public const SEVERITY = [
        'debug' => 0,
        'info' => 1,
        'notice' => 2,
        'warning' => 3,
        'error' => 4,
        'critical' => 5,
        'alert' => 6,
        'emergency' => 7,
    ];

    private readonly string $path;

    private readonly string $threshold;

    /**
     * @param string $path log file (created on first write; parent dir must exist)
     * @param string $threshold lowest severity to keep, one of SEVERITY keys
     */
    public function __construct(string $path, string $threshold = 'debug')
    {
        if (!array_key_exists($threshold, self::SEVERITY)) {
            throw new \OutOfRangeException(
                sprintf('Unknown log threshold [%s]; use one of: %s.', $threshold, implode(', ', self::severityList())),
            );
        }

        $this->path = $path;
        $this->threshold = $threshold;
    }

    /** @return list<string> */
    public static function severityList(): array
    {
        return array_keys(self::SEVERITY);
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $level = strtolower($level);

        if (self::severity($level) < self::SEVERITY[$this->threshold]) {
            return;
        }

        $this->write([
            'timestamp' => gmdate('c'),
            'level' => $level,
            'message' => $message,
            'context' => Redactor::flattenSafely($context),
        ]);
    }

    /**
     * Write one complete structured event without adding the generic
     * level/message/context envelope.
     *
     * @param array<string, mixed> $event
     */
    public function logEvent(array $event): void
    {
        $level = strtolower((string) ($event['level'] ?? 'info'));

        if (self::severity($level) < self::SEVERITY[$this->threshold]) {
            return;
        }

        $event['timestamp'] = (string) ($event['timestamp'] ?? gmdate('c'));
        $event['level'] = $level;

        if (isset($event['context']) && is_array($event['context'])) {
            $event['context'] = Redactor::flattenSafely($event['context']);
        }

        foreach (array_keys($event) as $key) {
            if (Redactor::isSensitive((string) $key)) {
                $event[$key] = '[redacted]';
            }
        }

        $this->write($event);
    }

    /**
     * @param array<string, mixed> $event
     */
    private function write(array $event): void
    {
        $line = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";

        if (@file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException(sprintf('Logger could not append to [%s].', $this->path));
        }
    }

    private static function severity(string $level): int
    {
        return self::SEVERITY[$level] ?? 99;
    }

    public function emergency(string $message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    public function alert(string $message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function notice(string $message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }
}
