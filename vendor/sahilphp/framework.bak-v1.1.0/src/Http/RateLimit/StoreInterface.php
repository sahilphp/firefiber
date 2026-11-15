<?php

declare(strict_types=1);

namespace Sahil\Http\RateLimit;

/** Storage contract for rate-limit counters shared across HTTP requests. */
interface StoreInterface
{
    /**
     * Whether the key already has recorded attempts.
     */
    public function has(string $key): bool;

    /**
     * Record one attempt for the key and return the gate result.
     */
    public function increment(string $key): RateLimitResult;

    /** Clear counters for a key. */
    public function clear(string $key): void;
}
