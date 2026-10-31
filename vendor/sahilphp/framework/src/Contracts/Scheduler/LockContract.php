<?php

declare(strict_types=1);

namespace Sahil\Contracts\Scheduler;

/**
 * Mutual exclusion for scheduled work.
 *
 * `acquire()` never waits: a second process holding the same name makes it
 * return false, which the runner reports as a skipped run. Locks are
 * re-entrant per instance only in the sense that acquiring a name already
 * held by the same lock instance fails too — a task never runs twice
 * through the same lock object.
 *
 * Implementations: filesystem (single host) and database advisory locks
 * (multiple hosts sharing one database).
 */
interface LockContract
{
    /**
     * Try to take the lock without blocking.
     */
    public function acquire(string $name): bool;

    /**
     * Release the lock. Safe to call when it is not held.
     */
    public function release(string $name): void;
}
