<?php

declare(strict_types=1);

namespace Sahil\Testing;

use PHPUnit\Framework\Assert;
use Sahil\Support\Logging\LoggerContract;

/**
 * In-memory {@see LoggerContract} for tests.
 *
 * Replace the real logger with this (see `ApplicationTestCase::swap()`) and
 * assert what the application reported without touching the filesystem or
 * parsing log files. Records keep level, message and context.
 */
final class TestLogger implements LoggerContract
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    private array $records = [];

    /**
     * @param array<string, mixed> $context
     */
    public function log(string $level, string $message, array $context = []): void
    {
        $this->records[] = [
            'level' => strtolower($level),
            'message' => $message,
            'context' => $context,
        ];
    }

    /** @param array<string, mixed> $context */
    public function emergency(string $message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function alert(string $message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function critical(string $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function notice(string $message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    /**
     * @return list<array{level: string, message: string, context: array<string, mixed>}>
     */
    public function records(?string $level = null): array
    {
        if ($level === null) {
            return $this->records;
        }

        return array_values(array_filter(
            $this->records,
            static fn (array $record): bool => $record['level'] === strtolower($level),
        ));
    }

    public function assertLogged(string $level, ?string $messageContains = null): self
    {
        $matching = array_values(array_filter(
            $this->records($level),
            static fn (array $record): bool => $messageContains === null
                || str_contains($record['message'], $messageContains),
        ));

        Assert::assertNotSame([], $matching, sprintf(
            "Expected a [%s] log record%s, but none was written.\nRecorded:\n%s",
            $level,
            $messageContains === null ? '' : sprintf(' whose message contains [%s]', $messageContains),
            $this->excerpt(),
        ));

        return $this;
    }

    public function assertNothingLogged(): self
    {
        Assert::assertSame([], $this->records, "Expected no log records, but some were written.\nRecorded:\n".$this->excerpt());

        return $this;
    }

    public function flush(): void
    {
        $this->records = [];
    }

    private function excerpt(int $limit = 2000): string
    {
        if ($this->records === []) {
            return '  (none)';
        }

        $lines = array_map(
            static fn (array $record): string => sprintf('  %s: %s', $record['level'], $record['message']),
            $this->records,
        );

        $text = implode("\n", $lines);

        return strlen($text) <= $limit ? $text : substr($text, 0, $limit)."\n  …";
    }
}
