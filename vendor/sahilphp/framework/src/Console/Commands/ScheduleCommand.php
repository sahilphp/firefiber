<?php

declare(strict_types=1);

namespace Sahil\Console\Commands;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Sahil\Console\Command;
use Sahil\Console\CommandInput;
use Sahil\Console\CommandOutput;
use Sahil\Console\ConsoleException;
use Sahil\Contracts\Scheduler\ClockContract;
use Sahil\Database\ConnectionException;
use Sahil\Scheduler\Schedule;
use Sahil\Scheduler\ScheduleRunner;
use Sahil\Scheduler\SchedulerException;
use Throwable;

/**
 * Scheduler inspection and the cron entry point.
 */
final class ScheduleCommand extends Command
{
    public function __construct(private readonly string $command)
    {
    }

    public function name(): string
    {
        return $this->command;
    }

    public function description(): string
    {
        return $this->command === 'schedule:list'
            ? 'List scheduled tasks, their cadence and next run.'
            : 'Run the tasks due in the current minute (cron entry point).';
    }

    public function usage(): string
    {
        return $this->command === 'schedule:list'
            ? 'php sahil schedule:list [--at=DATETIME]'
            : 'php sahil schedule:run [--at=DATETIME] [--only=Name]';
    }

    public function category(): string
    {
        return 'Scheduler';
    }

    public function optionSpecs(): array
    {
        return [
            'at' => 'DATETIME',
            'only' => 'Name',
        ];
    }

    /**
     * @return list<string>
     */
    public function examples(): array
    {
        return [
            'php sahil schedule:list',
            'php sahil schedule:run',
            'php sahil schedule:run --at="2026-01-01 09:00" --only=hourly-report',
        ];
    }

    public function execute(CommandInput $input, CommandOutput $output): int
    {
        $input->rejectExtraArguments(0, $this->usage());
        $container = $this->app()->container();
        $timezone = new DateTimeZone((string) $this->app()->config()->get('schedule.timezone', 'UTC'));
        $at = null;

        if ($input->option('at') !== null) {
            try {
                $at = new DateTimeImmutable((string) $input->option('at'), $timezone);
            } catch (Exception) {
                throw new ConsoleException(sprintf(
                    'invalid --at value [%s]; use "Y-m-d H:i[:s]" in %s.',
                    $input->option('at'),
                    $timezone->getName(),
                ));
            }
        }

        try {
            /** @var Schedule $schedule */
            $schedule = $container->make(Schedule::class);
            $now = $at ?? $container->make(ClockContract::class)->now();

            if ($this->command === 'schedule:list') {
                $output->line(sprintf(
                    'schedule (%s, %d task(s))',
                    $schedule->timezone(),
                    count($schedule->tasks()),
                ));

                if ($schedule->isEmpty()) {
                    $output->line('  no scheduled tasks (define them in routes/schedule.php)');

                    return 0;
                }

                foreach ($schedule->tasks() as $task) {
                    $output->line(sprintf(
                        '  %-28s %-14s %-20s overlap=%-3s timeout=%-4s next=%s',
                        $task->name(),
                        $task->expression(),
                        $task->timezone(),
                        $task->preventsOverlap() ? 'yes' : 'no',
                        $task->timeout() > 0 ? $task->timeout().'s' : '-',
                        $task->nextRunAfter($now)->format('Y-m-d H:i T'),
                    ));
                }

                return 0;
            }

            /** @var ScheduleRunner $runner */
            $runner = $container->make(ScheduleRunner::class);
            $report = $runner->runAt($now, $input->option('only'));

            $output->line(sprintf(
                'schedule:run at %s (%s)',
                $report->at->setTimezone($timezone)->format('Y-m-d H:i:s T'),
                $timezone->getName(),
            ));

            if ($report->isEmpty()) {
                $output->line('  no tasks due');

                return 0;
            }

            foreach ($report->results() as $result) {
                $detail = $result->message !== '' ? ' — '.$result->message : '';
                $duration = $result->seconds > 0 ? sprintf(' (%.3fs)', $result->seconds) : '';

                $output->line(sprintf('  %-8s %s%s%s', $result->status, $result->task, $detail, $duration));
            }

            $output->line(sprintf(
                '%d ran, %d skipped, %d failed',
                $report->ran(),
                $report->skipped(),
                $report->failedCount(),
            ));

            return $report->failed() ? 1 : 0;
        } catch (SchedulerException $error) {
            throw new ConsoleException($error->getMessage());
        } catch (ConnectionException $error) {
            throw new ConsoleException($error->getMessage());
        } catch (Throwable $error) {
            throw new ConsoleException($error->getMessage());
        }
    }
}
