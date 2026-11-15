<?php

declare(strict_types=1);

namespace Sahil\Http\RateLimit;

/** In-memory `StoreInterface` implementation. Not shared across processes. */
final class ArrayStore implements StoreInterface
{
    /** @var array<string, array{attempts: int, firstAttempt: int}> */
    private array $data = [];

    public function __construct(
        private readonly int $limit,
        private readonly int $window,
    ) {
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function increment(string $key): RateLimitResult
    {
        $now = time();

        if (!array_key_exists($key, $this->data)) {
            $this->data[$key] = ['attempts' => 0, 'firstAttempt' => $now];
        }

        $entry = &$this->data[$key];
        $entry['attempts']++;

        $allowed = $entry['attempts'] <= $this->limit;
        $remaining = max(0, $this->limit - $entry['attempts']);
        $retryAfter = $allowed ? 0 : max(0, $entry['firstAttempt'] + $this->window - $now);

        return new RateLimitResult($allowed, $remaining, $retryAfter);
    }

    public function clear(string $key): void
    {
        unset($this->data[$key]);
    }
}
