<?php

declare(strict_types=1);

namespace Sahil\Http\RateLimit;

/**
 * Rate limiter against an injected `StoreInterface`.
 *
 * The limiter is a deterministic gate: each `check()` call records one
 * attempt and returns whether it is permitted. Counters live in the
 * injected storage so they survive across requests when a shared store
 * (Redis, database, etc.) is bound.
 */
final class RateLimiter
{
    public function __construct(
        private readonly StoreInterface $store,
    ) {
    }

    /**
     * Record an attempt and return the gate result.
     */
    public function check(string $key): RateLimitResult
    {
        return $this->store->increment($key);
    }

    public function allowed(string $key): bool
    {
        return $this->check($key)->allowed;
    }

    public function remaining(string $key): int
    {
        return $this->check($key)->remaining;
    }

    public function retryAfter(string $key): int
    {
        return $this->check($key)->retryAfter;
    }

    public function reset(string $key): void
    {
        $this->store->clear($key);
    }
}
