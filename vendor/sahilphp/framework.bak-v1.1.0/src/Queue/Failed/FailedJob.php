<?php

declare(strict_types=1);

namespace Sahil\Queue\Failed;

/**
 * A job that exhausted its attempts.
 *
 * The payload is kept verbatim so the job can be inspected and replayed;
 * the exception is the message of the last failure, not a stack trace.
 */
final class FailedJob
{
    public function __construct(
        public readonly string $id,
        public readonly string $queue,
        public readonly string $payload,
        public readonly string $exception,
        public readonly string $failedAt,
    ) {
    }
}
