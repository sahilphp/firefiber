<?php

declare(strict_types=1);

namespace Sahil\Scheduler;

use DateTimeImmutable;

/**
 * Everything that happened in one `schedule:run` invocation.
 *
 * Only tasks that were due appear here; a minute with no due work produces
 * an empty report (and the command exits 0).
 */
final class ScheduleReport
{
    /**
     * @param list<TaskResult> $results
     */
    public function __construct(
        public readonly DateTimeImmutable $at,
        public readonly array $results,
    ) {
    }

    /**
     * @return list<TaskResult>
     */
    public function results(): array
    {
        return $this->results;
    }

    public function isEmpty(): bool
    {
        return $this->results === [];
    }

    public function failed(): bool
    {
        foreach ($this->results as $result) {
            if ($result->isFailure()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<TaskResult>
     */
    public function failures(): array
    {
        return array_values(array_filter(
            $this->results,
            static fn (TaskResult $result): bool => $result->isFailure(),
        ));
    }

    public function ran(): int
    {
        return $this->count(TaskResult::RAN);
    }

    public function skipped(): int
    {
        return $this->count(TaskResult::SKIPPED);
    }

    public function failedCount(): int
    {
        return $this->count(TaskResult::FAILED);
    }

    private function count(string $status): int
    {
        $count = 0;

        foreach ($this->results as $result) {
            if ($result->status === $status) {
                $count++;
            }
        }

        return $count;
    }
}
