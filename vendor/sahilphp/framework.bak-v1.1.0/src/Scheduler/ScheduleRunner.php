<?php

declare(strict_types=1);

namespace Sahil\Scheduler;

use DateTimeImmutable;
use Sahil\Contracts\Scheduler\ClockContract;
use Sahil\Contracts\Scheduler\LockContract;
use Sahil\Support\Logging\LoggerContract;
use Throwable;

/**
 * Runs the tasks that are due in one minute.
 *
 * Semantics:
 *  - a task runs when its cron expression matches the wall-clock minute in
 *    its own timezone; seconds are ignored, so a cron job that starts at
 *    09:00:47 still runs the 09:00 task;
 *  - a minute that was missed is NOT back-filled — starting the runner at
 *    09:01 does nothing for the 09:00 task (standard cron behaviour);
 *  - overlap protection is per task (`withoutOverlapping()`): if the lock
 *    cannot be taken, the run is reported as skipped and the task is not
 *    executed;
 *  - a throwing task is recorded as failed, logged, and does not stop the
 *    remaining tasks; the lock is always released;
 *  - a task past its timeout is recorded as failed (see {@see TimeoutGuard}
 *    for when it is really interrupted).
 */
final class ScheduleRunner
{
    public function __construct(
        private readonly Schedule $schedule,
        private readonly ClockContract $clock,
        private readonly LockContract $lock,
        private readonly ?LoggerContract $logger = null,
        private readonly ?TimeoutGuard $timeouts = null,
    ) {
    }

    /**
     * Run the schedule for the clock's current minute.
     */
    public function run(?string $only = null): ScheduleReport
    {
        return $this->runAt($this->clock->now(), $only);
    }

    /**
     * Run the schedule for an explicit instant (tests, `--at=...`).
     */
    public function runAt(DateTimeImmutable $now, ?string $only = null): ScheduleReport
    {
        $results = [];

        foreach ($this->schedule->tasks() as $task) {
            if ($only !== null && $task->name() !== $only) {
                continue;
            }

            if (!$task->isDue($now)) {
                continue;
            }

            $results[] = $this->runTask($task);
        }

        return new ScheduleReport($now, $results);
    }

    private function runTask(Task $task): TaskResult
    {
        $guard = $this->timeouts ?? new TimeoutGuard();
        $locked = $task->preventsOverlap();

        if ($locked && !$this->lock->acquire($task->lockKey())) {
            $this->log('warning', 'Scheduled task skipped: the previous run is still in progress.', $task);

            return TaskResult::skipped($task->name(), 'the previous run is still in progress');
        }

        $started = microtime(true);

        try {
            $guard->run($task->name(), $task->run(...), $task->timeout());
        } catch (TaskTimeoutException $timeout) {
            $elapsed = microtime(true) - $started;

            $this->log('error', $timeout->getMessage(), $task, ['seconds' => round($elapsed, 3)]);

            return TaskResult::failed($task->name(), $timeout->getMessage(), $elapsed);
        } catch (Throwable $failure) {
            $elapsed = microtime(true) - $started;

            $this->log('error', 'Scheduled task failed: '.$failure->getMessage(), $task, [
                'exception' => $failure::class,
                'seconds' => round($elapsed, 3),
            ]);

            return TaskResult::failed($task->name(), $failure->getMessage(), $elapsed);
        } finally {
            if ($locked) {
                $this->lock->release($task->lockKey());
            }
        }

        $elapsed = microtime(true) - $started;

        $this->log('debug', 'Scheduled task completed.', $task, ['seconds' => round($elapsed, 3)]);

        return TaskResult::ran($task->name(), $elapsed);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function log(string $level, string $message, Task $task, array $context = []): void
    {
        if ($this->logger === null) {
            return;
        }

        $this->logger->log($level, $message, array_merge([
            'task' => $task->name(),
            'expression' => $task->expression(),
            'timezone' => $task->timezone(),
        ], $context));
    }
}
