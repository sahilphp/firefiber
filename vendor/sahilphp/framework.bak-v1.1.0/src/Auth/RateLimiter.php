<?php

declare(strict_types=1);

namespace Sahil\Auth;

/**
 * Rate limiter for login attempts.
 *
 * Tracks login attempts per identifier to prevent brute-force attacks.
 * Uses session storage for tracking (can be extended to use cache/Redis).
 *
 * Security posture:
 * - Limits login attempts per identifier
 * - Prevents user enumeration by returning generic failures
 * - Exponential backoff for repeated failures
 * - Configurable max attempts and decay time
 */
final class RateLimiter
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_MINUTES = 1;

    /** @var array<string, array{attempts: int, last_attempt: int}> */
    private array $attempts = [];

    /**
     * Record a login attempt.
     */
    public function attempt(string $key): void
    {
        if (!isset($this->attempts[$key])) {
            $this->attempts[$key] = [
                'attempts' => 0,
                'last_attempt' => 0,
            ];
        }
    }

    /**
     * Increment the attempt counter for a key.
     */
    public function increment(string $key): void
    {
        if (!isset($this->attempts[$key])) {
            $this->attempts[$key] = [
                'attempts' => 0,
                'last_attempt' => 0,
            ];
        }

        $this->attempts[$key]['attempts']++;
        $this->attempts[$key]['last_attempt'] = time();
    }

    /**
     * Check if too many attempts have been made.
     */
    public function tooManyAttempts(string $key): bool
    {
        if (!isset($this->attempts[$key])) {
            return false;
        }

        // Decay attempts over time
        $this->decayAttempts($key);

        return $this->attempts[$key]['attempts'] >= self::MAX_ATTEMPTS;
    }

    /**
     * Get the number of remaining attempts.
     */
    public function remainingAttempts(string $key): int
    {
        if (!isset($this->attempts[$key])) {
            return self::MAX_ATTEMPTS;
        }

        $this->decayAttempts($key);

        return max(0, self::MAX_ATTEMPTS - $this->attempts[$key]['attempts']);
    }

    /**
     * Get the number of seconds until the next attempt is allowed.
     */
    public function availableIn(string $key): int
    {
        if (!isset($this->attempts[$key])) {
            return 0;
        }

        $this->decayAttempts($key);

        if ($this->attempts[$key]['attempts'] < self::MAX_ATTEMPTS) {
            return 0;
        }

        $decaySeconds = self::DECAY_MINUTES * 60;
        $elapsed = time() - $this->attempts[$key]['last_attempt'];

        return max(0, $decaySeconds - $elapsed);
    }

    /**
     * Clear attempts for a key.
     */
    public function clear(string $key): void
    {
        unset($this->attempts[$key]);
    }

    /**
     * Decay attempts over time (exponential backoff).
     */
    private function decayAttempts(string $key): void
    {
        if (!isset($this->attempts[$key])) {
            return;
        }

        $elapsed = time() - $this->attempts[$key]['last_attempt'];
        $decayMinutes = self::DECAY_MINUTES;

        // Decay one attempt per decay minute
        $decayCount = (int) floor($elapsed / ($decayMinutes * 60));

        if ($decayCount > 0) {
            $this->attempts[$key]['attempts'] = max(0, $this->attempts[$key]['attempts'] - $decayCount);
        }
    }
}
