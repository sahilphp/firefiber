<?php

declare(strict_types=1);

namespace Sahil\Scheduler;

/**
 * A task exceeded its configured timeout.
 *
 * The runner records this as a failure; a timed-out task does not keep the
 * lock, so the next scheduled opportunity is free to run.
 */
final class TaskTimeoutException extends SchedulerException
{
    public static function after(string $task, int $seconds): self
    {
        return new self(sprintf('Task [%s] timed out after %d second(s).', $task, $seconds));
    }
}
