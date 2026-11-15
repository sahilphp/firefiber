<?php

declare(strict_types=1);

namespace Sahil\Queue;

use Sahil\Contracts\Queue\DispatcherContract;
use Sahil\Contracts\Queue\Jobs\JobContract;
use Sahil\Contracts\Queue\QueueContract;
use Sahil\Contracts\Queue\QueueException;
use Sahil\Database\Connection;
use Throwable;

/**
 * Resolves queue connections from configuration and serializes jobs.
 *
 * Stores are built lazily, so a request that never queues anything never
 * touches the queue tables. Only the `database` driver exists: this
 * framework ships one database connection, so `connection` in the queue
 * configuration names the queue connection, not a second database.
 */
final class QueueManager implements DispatcherContract
{
    /**
     * Queue a job lands on when it names none.
     */
    public const DEFAULT_QUEUE = 'default';

    /** @var array<string, QueueContract> */
    private array $stores = [];

    /**
     * @param array<string, array<string, mixed>> $connections the `queue.connections` configuration
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly array $connections = [],
        private readonly string $defaultConnection = 'database',
    ) {
    }

    /**
     * The store for a configured queue connection (default when null).
     */
    public function store(?string $name = null): QueueContract
    {
        $name ??= $this->defaultConnection;

        if (isset($this->stores[$name])) {
            return $this->stores[$name];
        }

        $configuration = $this->connections[$name] ?? null;

        if ($configuration === null) {
            throw new QueueException(sprintf('Queue connection [%s] is not configured.', $name));
        }

        $driver = (string) ($configuration['driver'] ?? 'database');

        if ($driver !== 'database') {
            throw new QueueException(sprintf(
                'Unsupported queue driver [%s] on connection [%s]; only [database] is available.',
                $driver,
                $name,
            ));
        }

        return $this->stores[$name] = new DatabaseQueue(
            $this->connection,
            (string) ($configuration['table'] ?? 'jobs'),
        );
    }

    public function dispatch(JobContract $job, int $delay = 0): string
    {
        return $this->pushOn($job->queue() ?? self::DEFAULT_QUEUE, $job, $delay);
    }

    public function pushOn(string $queue, JobContract $job, int $delay = 0): string
    {
        return $this->pushRaw($queue, $this->serialize($job), $delay);
    }

    public function pushRaw(string $queue, string $payload, int $delay = 0): string
    {
        return $this->store()->push($queue, $payload, $delay);
    }

    /**
     * Jobs travel as native serialized objects, so a job must hold plain
     * data only — a closure or resource cannot be queued.
     */
    private function serialize(JobContract $job): string
    {
        try {
            return serialize($job);
        } catch (Throwable $failure) {
            throw new QueueException(sprintf(
                'Job [%s] cannot be queued: %s',
                $job::class,
                $failure->getMessage(),
            ));
        }
    }
}
