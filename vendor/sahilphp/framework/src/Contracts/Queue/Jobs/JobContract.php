<?php

declare(strict_types=1);

namespace Sahil\Contracts\Queue\Jobs;

/**
 * A unit of work that can be queued and executed by a worker.
 *
 * Retry policy is expressed per job and falls back to the worker's
 * configuration: returning null means "use the worker default".
 */
interface JobContract
{
    /**
     * Perform the work. Anything thrown marks the attempt as failed.
     */
    public function handle(): mixed;

    /**
     * Queue this job asks for, or null for the configured default queue.
     */
    public function queue(): ?string;

    /**
     * Maximum number of attempts (including the first one), or null for
     * the worker default.
     */
    public function maxAttempts(): ?int;

    /**
     * Seconds to wait before the next attempt, given the attempt number
     * that just failed (1 for the first failure). Null for the worker
     * default.
     */
    public function retryDelay(int $attempt): ?int;
}
