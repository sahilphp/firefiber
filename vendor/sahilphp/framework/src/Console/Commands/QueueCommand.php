<?php

declare(strict_types=1);

namespace Sahil\Console\Commands;

use Sahil\Console\Command;
use Sahil\Console\CommandInput;
use Sahil\Console\CommandOutput;
use Sahil\Console\ConsoleException;
use Sahil\Contracts\Queue\DispatcherContract;
use Sahil\Contracts\Queue\FailedJobRepositoryContract;
use Sahil\Contracts\Queue\QueueContract;
use Sahil\Database\ConnectionException;
use Sahil\Queue\Worker;
use Throwable;

/**
 * Queue inspection and worker commands.
 */
final class QueueCommand extends Command
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
        return match ($this->command) {
            'queue:work' => 'Process jobs from a queue (until stopped, or --once).',
            'queue:failed' => 'List failed jobs with their queue, time and reason.',
            'queue:retry' => 'Replay failed job(s): pass an id or --all.',
            'queue:flush' => 'Delete failed job records for a queue.',
            default => 'Queue command.',
        };
    }

    public function usage(): string
    {
        return match ($this->command) {
            'queue:work' => 'php sahil queue:work [--once] [--max=N] [--queue=Name]',
            'queue:retry' => 'php sahil queue:retry <id>|--all [--queue=Name]',
            default => 'php sahil '.$this->command.' [--queue=Name]',
        };
    }

    public function category(): string
    {
        return 'Queues';
    }

    public function optionSpecs(): array
    {
        return [
            'queue' => 'Name',
            'once' => null,
            'max' => 'N',
            'all' => null,
        ];
    }

    /**
     * @return list<string>
     */
    public function examples(): array
    {
        return [
            'php sahil queue:work --once',
            'php sahil queue:work --max=100 --queue=default',
            'php sahil queue:failed',
            'php sahil queue:retry --all',
        ];
    }

    public function execute(CommandInput $input, CommandOutput $output): int
    {
        $input->rejectExtraArguments($this->command === 'queue:retry' ? 1 : 0, $this->usage());
        $container = $this->app()->container();
        $configuration = $this->app()->config();
        $queueName = $input->option('queue') ?? (string) $configuration->get('queue.work.queue', 'default');
        $limit = $input->option('max') === null ? null : max(1, (int) $input->option('max'));

        try {
            if ($this->command === 'queue:work') {
                $worker = new Worker(
                    $container->make(QueueContract::class),
                    $container->make(FailedJobRepositoryContract::class),
                    $queueName,
                    (int) $configuration->get('queue.retry.max_attempts', 3),
                    (int) $configuration->get('queue.retry.delay', 0),
                    (int) $configuration->get('queue.work.sleep', 1),
                );

                $output->line(sprintf(
                    'queue:work on [%s]%s',
                    $queueName,
                    $input->flag('once')
                        ? ' — one job or nothing'
                        : ($limit === null ? ' — interrupt to stop' : sprintf(' — at most %d job(s)', $limit)),
                ));

                $processed = $input->flag('once')
                    ? ($worker->runNextJob() ? 1 : 0)
                    : $worker->work($limit);

                $output->line(sprintf('processed %d job(s)', $processed));

                return 0;
            }

            /** @var FailedJobRepositoryContract $failures */
            $failures = $container->make(FailedJobRepositoryContract::class);

            if ($this->command === 'queue:failed') {
                $jobs = $failures->all($queueName);

                if ($jobs === []) {
                    $output->line(sprintf('no failed jobs on [%s]', $queueName));

                    return 0;
                }

                foreach ($jobs as $job) {
                    $output->line(sprintf(
                        '  %s  %-20s %s  %s',
                        $job->id,
                        $job->queue,
                        $job->failedAt,
                        $job->exception,
                    ));
                }

                return 0;
            }

            if ($this->command === 'queue:flush') {
                $jobs = $failures->all($queueName);

                foreach ($jobs as $job) {
                    $failures->delete($job->id);
                }

                $output->line(sprintf('flushed %d failed job(s) on [%s]', count($jobs), $queueName));

                return 0;
            }

            // queue:retry
            $target = $input->argument(0);

            if (!$input->flag('all') && $target === null) {
                throw new ConsoleException('pass a job id or --all.');
            }

            if ($input->flag('all')) {
                $jobs = $failures->all($queueName);
            } else {
                $job = $failures->find((string) $target);

                if ($job === null) {
                    throw new ConsoleException(sprintf('no failed job [%s].', $target));
                }

                $jobs = [$job];
            }

            /** @var DispatcherContract $dispatcher */
            $dispatcher = $container->make(DispatcherContract::class);

            foreach ($jobs as $job) {
                $dispatcher->pushRaw($job->queue, $job->payload);
                $failures->delete($job->id);
                $output->line(sprintf('retried %s on [%s]', $job->id, $job->queue));
            }

            return 0;
        } catch (ConnectionException|ConsoleException $error) {
            throw new ConsoleException($error->getMessage());
        } catch (Throwable $error) {
            throw new ConsoleException($error->getMessage());
        }
    }
}
