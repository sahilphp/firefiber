<?php

declare(strict_types=1);

namespace Sahil\Scheduler;

/**
 * Outcome of one task in one schedule run.
 */
final class TaskResult
{
    public const RAN = 'ran';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    public function __construct(
        public readonly string $task,
        public readonly string $status,
        public readonly string $message = '',
        public readonly float $seconds = 0.0,
    ) {
    }

    public static function ran(string $task, float $seconds): self
    {
        return new self($task, self::RAN, '', $seconds);
    }

    public static function failed(string $task, string $message, float $seconds): self
    {
        return new self($task, self::FAILED, $message, $seconds);
    }

    public static function skipped(string $task, string $reason): self
    {
        return new self($task, self::SKIPPED, $reason);
    }

    public function wasSuccessful(): bool
    {
        return $this->status === self::RAN;
    }

    public function isFailure(): bool
    {
        return $this->status === self::FAILED;
    }
}
