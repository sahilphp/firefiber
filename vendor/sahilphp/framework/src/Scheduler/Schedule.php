<?php

declare(strict_types=1);

namespace Sahil\Scheduler;

use Closure;
use DateTimeImmutable;

/**
 * The registered tasks for this installation.
 *
 * Tasks are defined in `routes/schedule.php` and registered once at boot.
 * Names are unique: the name is the operator-facing identity used by
 * `schedule:list`, `schedule:run --only=…` and the overlap lock, so a
 * duplicate registration is a configuration error rather than a silent
 * replacement.
 */
final class Schedule
{
    /** @var array<string, Task> */
    private array $tasks = [];

    public function __construct(private readonly string $timezone = 'UTC')
    {
        if ($timezone === '') {
            throw new SchedulerException('The schedule timezone must not be empty.');
        }
    }

    /**
     * Default timezone for tasks that do not name one.
     */
    public function timezone(): string
    {
        return $this->timezone;
    }

    /**
     * Register a PHP task. The callback runs in this process, so it may
     * throw; the runner records the failure and keeps going with the rest.
     */
    public function call(string $name, Closure $callback): Task
    {
        if (isset($this->tasks[$name])) {
            throw new SchedulerException(sprintf('Task [%s] is already registered.', $name));
        }

        return $this->tasks[$name] = new Task($name, $callback, $this->timezone);
    }

    public function task(string $name): ?Task
    {
        return $this->tasks[$name] ?? null;
    }

    /**
     * Every task, in registration order.
     *
     * @return list<Task>
     */
    public function tasks(): array
    {
        return array_values($this->tasks);
    }

    public function isEmpty(): bool
    {
        return $this->tasks === [];
    }

    /**
     * Tasks due in the minute of `$now`, in registration order.
     *
     * @return list<Task>
     */
    public function dueAt(DateTimeImmutable $now, ?string $only = null): array
    {
        $due = [];

        foreach ($this->tasks as $task) {
            if ($only !== null && $task->name() !== $only) {
                continue;
            }

            if ($task->isDue($now)) {
                $due[] = $task;
            }
        }

        return $due;
    }
}
