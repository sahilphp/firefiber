<?php

declare(strict_types=1);

namespace Sahil\Http\RateLimit;

/** One rate-limit gate decision for a key. */
final class RateLimitResult
{
    public function __construct(
        public readonly bool $allowed,
        public readonly int $remaining,
        public readonly int $retryAfter,
    ) {
    }

    public function permit(): bool
    {
        return $this->allowed;
    }
}
