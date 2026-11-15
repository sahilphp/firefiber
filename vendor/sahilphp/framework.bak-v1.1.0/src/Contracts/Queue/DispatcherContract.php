<?php

declare(strict_types=1);

namespace Sahil\Contracts\Queue;

use Sahil\Contracts\Queue\Jobs\JobContract;

/**
 * Turns job objects into queue payloads.
 *
 * The dispatcher owns serialization, so a job never needs to know which
 * driver or table it ends up in.
 */
interface DispatcherContract
{
    /**
     * Queue a job on the queue it names, or on the configured default.
     *
     * @param int $delay seconds before the job becomes available
     *
     * @return string the store job id
     */
    public function dispatch(JobContract $job, int $delay = 0): string;

    /**
     * Queue a job on an explicitly named queue.
     *
     * @param int $delay seconds before the job becomes available
     *
     * @return string the store job id
     */
    public function pushOn(string $queue, JobContract $job, int $delay = 0): string;

    /**
     * Queue an already serialized payload.
     *
     * @param int $delay seconds before the job becomes available
     *
     * @return string the store job id
     */
    public function pushRaw(string $queue, string $payload, int $delay = 0): string;
}
