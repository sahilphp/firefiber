<?php

declare(strict_types=1);

namespace Sahil\Contracts\Queue;

/**
 * A durable job store.
 *
 * Payloads are opaque strings to the store: a queue routes and reserves
 * rows, it does not care what a job contains. Every mutating operation is
 * keyed by the job id returned from {@see push()}.
 */
interface QueueContract
{
    /**
     * Append a payload, optionally making it available only after `$delay`
     * seconds. Returns the job id.
     */
    public function push(string $queue, string $payload, int $delay = 0): string;

    /**
     * Alias of {@see push()} for a delayed job; reads better at call sites.
     */
    public function later(string $queue, string $payload, int $delay): string;

    /**
     * Reserve the oldest available job on `$queue`, incrementing its
     * attempt count. Returns null when nothing is available.
     */
    public function pop(string $queue): ?QueuedJob;

    /**
     * Acknowledge a job, removing it from the queue.
     */
    public function delete(string $id): void;

    /**
     * Put a reserved job back, available again after `$delay` seconds.
     * The attempt count is preserved (it was incremented at reservation).
     */
    public function release(string $id, int $delay = 0): void;

    /**
     * Number of jobs on the queue, including delayed and reserved ones.
     */
    public function size(string $queue): int;

    /**
     * Whether any job is ready to be reserved right now.
     */
    public function isEmpty(string $queue): bool;
}
