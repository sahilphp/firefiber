<?php

declare(strict_types=1);

namespace Sahil\Scheduler;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * One scheduled task: a named PHP callable plus when it should run.
 *
 * Timing is expressed as a cron expression and a timezone. Everything is
 * evaluated against local wall-clock time in that zone, so a `dailyAt()`
 * task keeps its human meaning across daylight-saving changes: 09:00 is
 * 09:00 in that zone, which is a different UTC instant in summer and winter.
 *
 * A task that does not exist at a local time (the skipped hour when clocks
 * move forward) simply does not run that day; a local time that occurs twice
 * (when clocks move back) matches both occurrences. The runner's locks are
 * the only de-duplication mechanism, and only while a run is still going.
 */
final class Task
{
    private const MAX_SEARCH_MINUTES = 527_040; // 366 days

    private CronExpression $cron;

    private DateTimeZone $zone;

    private string $description = '';

    private bool $overlapping = false;

    private int $timeout = 0;

    public function __construct(
        private readonly string $name,
        private readonly Closure $callback,
        string $timezone = 'UTC',
    ) {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,99}$/', $name) !== 1) {
            throw new SchedulerException(sprintf(
                'Task name [%s] is invalid; use letters, digits, dot, underscore, colon or dash (max 100).',
                $name,
            ));
        }

        $this->zone = self::timezoneObject($timezone);
        $this->cron = CronExpression::parse('* * * * *');
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function describe(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function expression(): string
    {
        return $this->cron->expression();
    }

    public function timezone(): string
    {
        return $this->zone->getName();
    }

    public function timeout(): int
    {
        return $this->timeout;
    }

    public function preventsOverlap(): bool
    {
        return $this->overlapping;
    }

    /**
     * Key used for the overlap lock.
     */
    public function lockKey(): string
    {
        return 'schedule:'.$this->name;
    }

    /* ---------- schedules ---------- */

    public function everyMinute(): self
    {
        return $this->cron('* * * * *');
    }

    public function every(int $minutes): self
    {
        if ($minutes < 1 || $minutes > 59) {
            throw new SchedulerException(sprintf('every() expects 1-59 minutes, got %d.', $minutes));
        }

        return $this->cron(sprintf('*/%d * * * *', $minutes));
    }

    public function hourly(): self
    {
        return $this->cron('0 * * * *');
    }

    public function hourlyAt(int $minute): self
    {
        if ($minute < 0 || $minute > 59) {
            throw new SchedulerException(sprintf('hourlyAt() expects 0-59, got %d.', $minute));
        }

        return $this->cron(sprintf('%d * * * *', $minute));
    }

    public function daily(): self
    {
        return $this->cron('0 0 * * *');
    }

    public function dailyAt(string $time): self
    {
        [$hour, $minute] = self::parseTime($time);

        return $this->cron(sprintf('%d %d * * *', $minute, $hour));
    }

    public function weekly(): self
    {
        return $this->cron('0 0 * * 0');
    }

    public function weeklyOn(int $dayOfWeek, string $time = '00:00'): self
    {
        if ($dayOfWeek < 0 || $dayOfWeek > 6) {
            throw new SchedulerException(sprintf('weeklyOn() expects 0-6 (Sunday=0), got %d.', $dayOfWeek));
        }

        [$hour, $minute] = self::parseTime($time);

        return $this->cron(sprintf('%d %d * * %d', $minute, $hour, $dayOfWeek));
    }

    public function monthly(): self
    {
        return $this->cron('0 0 1 * *');
    }

    public function monthlyOn(int $dayOfMonth, string $time = '00:00'): self
    {
        if ($dayOfMonth < 1 || $dayOfMonth > 31) {
            throw new SchedulerException(sprintf('monthlyOn() expects 1-31, got %d.', $dayOfMonth));
        }

        [$hour, $minute] = self::parseTime($time);

        return $this->cron(sprintf('%d %d %d * *', $minute, $hour, $dayOfMonth));
    }

    public function cron(string $expression): self
    {
        $this->cron = CronExpression::parse($expression);

        return $this;
    }

    /**
     * Evaluate this task's wall clock in another timezone.
     */
    public function inTimezone(string $timezone): self
    {
        $this->zone = self::timezoneObject($timezone);

        return $this;
    }

    /**
     * Refuse to start while the previous run is still going (lock).
     */
    public function withoutOverlapping(bool $enabled = true): self
    {
        $this->overlapping = $enabled;

        return $this;
    }

    /**
     * Seconds the task may run before it is reported (and, when the PHP
     * process is signalled, interrupted). 0 disables the timeout.
     */
    public function timeoutAfter(int $seconds): self
    {
        if ($seconds < 0) {
            throw new SchedulerException('A task timeout cannot be negative.');
        }

        $this->timeout = $seconds;

        return $this;
    }

    /* ---------- evaluation ---------- */

    /**
     * Whether the task runs in the minute of the given instant.
     */
    public function isDue(DateTimeImmutable $now): bool
    {
        return $this->cron->isDue($now->setTimezone($this->zone));
    }

    /**
     * The first matching minute strictly after `$after`, in the task's
     * timezone. Used for inspection output; execution always matches the
     * wall clock of the actual instant.
     */
    public function nextRunAfter(DateTimeImmutable $after): DateTimeImmutable
    {
        // Search over real UTC minutes, not local ones: that hits every
        // instant that exists (including the repeated hour when clocks move
        // back) and never invents a local time that was skipped.
        $utc = $after->setTimezone(new DateTimeZone('UTC'));

        $cursor = $utc
            ->setTime((int) $utc->format('G'), (int) $utc->format('i'), 0)
            ->modify('+1 minute');

        for ($step = 0; $step < self::MAX_SEARCH_MINUTES; $step++) {
            if ($this->isDue($cursor)) {
                return $cursor->setTimezone($this->zone);
            }

            $cursor = $cursor->modify('+1 minute');
        }

        throw new SchedulerException(sprintf(
            'Task [%s] has no matching run within a year of %s.',
            $this->name,
            $after->format(DATE_ATOM),
        ));
    }

    /**
     * Run the task. Any throwable propagates to the runner, which records
     * the failure and releases the lock.
     */
    public function run(): void
    {
        ($this->callback)();
    }

    /**
     * @return array{0: int, 1: int} hour, minute
     */
    private static function parseTime(string $time): array
    {
        if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim($time), $matches) !== 1) {
            throw new SchedulerException(sprintf(
                'Time [%s] is invalid; use HH:MM in 24-hour form.',
                $time,
            ));
        }

        return [(int) $matches[1], (int) $matches[2]];
    }

    private static function timezoneObject(string $timezone): DateTimeZone
    {
        try {
            return new DateTimeZone($timezone);
        } catch (Exception) {
            throw new SchedulerException(sprintf('Unknown timezone [%s].', $timezone));
        }
    }
}
