<?php

declare(strict_types=1);

namespace Sahil\Contracts\Queue;

/**
 * One job reserved from a queue.
 *
 * A reservation hands the payload to a worker together with the id needed
 * to acknowledge it and the attempt count, which the store increments at
 * reservation time so a crash mid-job still counts as an attempt.
 */
final class QueuedJob
{
    public function __construct(
        public readonly string $id,
        public readonly string $queue,
        public readonly string $payload,
        public readonly int $attempts,
    ) {
    }
}
