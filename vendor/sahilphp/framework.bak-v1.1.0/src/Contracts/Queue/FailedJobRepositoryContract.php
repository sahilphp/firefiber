<?php

declare(strict_types=1);

namespace Sahil\Contracts\Queue;

use Sahil\Queue\Failed\FailedJob;

/**
 * Storage for jobs that exhausted their attempts.
 *
 * Kept separate from {@see QueueContract}: a failed job is a record to
 * inspect and replay, not something a worker reserves.
 */
interface FailedJobRepositoryContract
{
    /**
     * Record a job that will not be retried.
     */
    public function log(FailedJob $job): void;

    /**
     * Every failed job on a queue, oldest first.
     *
     * @return list<FailedJob>
     */
    public function all(string $queue): array;

    public function find(string $id): ?FailedJob;

    public function delete(string $id): void;

    public function count(string $queue): int;

    public function isEmpty(string $queue): bool;
}
