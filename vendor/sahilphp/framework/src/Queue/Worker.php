<?php

declare(strict_types=1);

namespace Sahil\Queue;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Sahil\Contracts\Queue\FailedJobRepositoryContract;
use Sahil\Contracts\Queue\Jobs\JobContract;
use Sahil\Contracts\Queue\QueueContract;
use Sahil\Contracts\Queue\QueuedJob;
use Sahil\Queue\Failed\FailedJob;
use Throwable;

/**
 * Executes queued jobs.
 *
 * A reserved job whose handler throws is released back onto the queue with
 * a delay while attempts remain; once the attempt budget is exhausted it is
 * recorded in the failed-job store and removed from the queue, so a
 * poisonous job cannot spin forever.
 *
 * Retry policy is per job, falling back to the worker defaults taken from
 * `queue.retry.*`.
 */
final class Worker
{
    /** @var Closure(): DateTimeImmutable */
    private readonly Closure $clock;

    /**
     * @param string $queueName queue the worker consumes
     * @param int $maxAttempts default attempt budget when a job names none
     * @param int $retryDelay default delay in seconds between attempts
     * @param int $sleep seconds to wait between polls when the queue is empty
     * @param Closure(): DateTimeImmutable|null $clock
     */
    public function __construct(
        private readonly QueueContract $queue,
        private readonly FailedJobRepositoryContract $failures,
        private readonly string $queueName = QueueManager::DEFAULT_QUEUE,
        private readonly int $maxAttempts = 3,
        private readonly int $retryDelay = 0,
        private readonly int $sleep = 1,
        ?Closure $clock = null,
        /** @var list<class-string> $allowedJobClasses */
        private readonly array $allowedJobClasses = [],
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * Process jobs until `$maxJobs` have run (forever when null), sleeping
     * `$sleep` seconds whenever the queue is empty.
     *
     * @return int number of jobs processed
     */
    public function work(?int $maxJobs = null): int
    {
        $processed = 0;

        while ($maxJobs === null || $processed < $maxJobs) {
            if (!$this->runNextJob()) {
                sleep($this->sleep);

                continue;
            }

            $processed++;
        }

        return $processed;
    }

    /**
     * Reserve and process one job. Returns false when the queue is empty.
     */
    public function runNextJob(): bool
    {
        $reserved = $this->queue->pop($this->queueName);

        if ($reserved === null) {
            return false;
        }

        $this->process($reserved);

        return true;
    }

    public function queueName(): string
    {
        return $this->queueName;
    }

    private function process(QueuedJob $reserved): void
    {
        $job = $this->resolve($reserved->payload);

        if ($job === null) {
            // Nothing runnable: keep the payload for inspection instead of
            // retrying an unreadable job forever.
            $this->recordFailure($reserved, 'Payload does not contain a '.JobContract::class.'.');
            $this->queue->delete($reserved->id);

            return;
        }

        try {
            $job->handle();
        } catch (Throwable $failure) {
            $this->handleFailure($reserved, $job, $failure);

            return;
        }

        $this->queue->delete($reserved->id);
    }

    private function handleFailure(QueuedJob $reserved, JobContract $job, Throwable $failure): void
    {
        if ($reserved->attempts < ($job->maxAttempts() ?? $this->maxAttempts)) {
            $delay = max(0, $job->retryDelay($reserved->attempts) ?? $this->retryDelay);

            $this->queue->release($reserved->id, $delay);

            return;
        }

        $this->recordFailure($reserved, $failure->getMessage());
        $this->queue->delete($reserved->id);
    }

    private function recordFailure(QueuedJob $reserved, string $exception): void
    {
        $this->failures->log(new FailedJob(
            id: $reserved->id,
            queue: $reserved->queue,
            payload: $reserved->payload,
            exception: $exception,
            failedAt: ($this->clock)()->format('Y-m-d H:i:s'),
        ));
    }

    /**
     * A payload that does not unserialize is corrupt data, not a crash:
     * the warning is contained and the caller records the failure.
     */
    private function resolve(string $payload): ?JobContract
    {
        set_error_handler(static fn (): bool => true);

        try {
            // Object injection guard: a configured allow-list restricts the
            // classes a payload may instantiate. Leaving it empty keeps the
            // historical behaviour, where queue rows are trusted application
            // data — see `queue.allowed_jobs` in the starter kit.
            $job = $this->allowedJobClasses === []
                ? unserialize($payload)
                : unserialize($payload, ['allowed_classes' => $this->allowedJobClasses]);
        } catch (Throwable) {
            return null;
        } finally {
            restore_error_handler();
        }

        return $job instanceof JobContract ? $job : null;
    }
}
