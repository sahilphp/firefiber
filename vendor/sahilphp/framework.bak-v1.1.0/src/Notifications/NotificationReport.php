<?php

declare(strict_types=1);

namespace Sahil\Notifications;

/**
 * Per-channel outcomes of one {@see Notifier::send()} call, in `via()` order.
 *
 * Channels are independent: one failing channel does not stop the others, and
 * the report keeps each result so callers can react to a partial failure
 * instead of guessing.
 */
final class NotificationReport
{
    /** @var array<string, NotificationResult> */
    private array $results = [];

    public function add(NotificationResult $result): void
    {
        $this->results[$result->channel] = $result;
    }

    /** @return array<string, NotificationResult> */
    public function results(): array
    {
        return $this->results;
    }

    public function forChannel(string $channel): ?NotificationResult
    {
        return $this->results[$channel] ?? null;
    }

    public function failed(): bool
    {
        foreach ($this->results as $result) {
            if (!$result->successful()) {
                return true;
            }
        }

        return false;
    }

    public function isEmpty(): bool
    {
        return $this->results === [];
    }

    /** @return list<string> */
    public function failedChannels(): array
    {
        return array_keys(array_filter(
            $this->results,
            static fn (NotificationResult $result): bool => !$result->successful(),
        ));
    }
}
