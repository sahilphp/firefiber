<?php

declare(strict_types=1);

namespace Sahil\Queue\Failed;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Sahil\Contracts\Queue\FailedJobRepositoryContract;
use Sahil\Database\Connection;
use Sahil\Database\Identifier;

/**
 * Failed jobs in the database, so an operator can inspect and replay them
 * long after the worker that recorded them exited.
 *
 * The table is created by the shipped migration; the repository never
 * creates schema at runtime.
 */
final class FailedJobRepository implements FailedJobRepositoryContract
{
    private readonly string $table;

    /** @var Closure(): DateTimeImmutable */
    private readonly Closure $clock;

    /**
     * @param Closure(): DateTimeImmutable|null $clock
     */
    public function __construct(
        private readonly Connection $connection,
        string $table = 'failed_jobs',
        ?Closure $clock = null,
    ) {
        Identifier::segments($table);

        $this->table = $table;
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function log(FailedJob $job): void
    {
        $this->connection->execute(
            sprintf(
                'INSERT INTO %s (%s, %s, %s, %s, %s) VALUES (?, ?, ?, ?, ?)',
                $this->quotedTable(),
                $this->quote('id'),
                $this->quote('queue'),
                $this->quote('payload'),
                $this->quote('exception'),
                $this->quote('failed_at'),
            ),
            [$job->id, $job->queue, $job->payload, $job->exception, $job->failedAt],
        );
    }

    public function all(string $queue): array
    {
        $rows = $this->connection->execute(
            sprintf(
                'SELECT %s, %s, %s, %s, %s FROM %s WHERE %s = ? ORDER BY %s ASC',
                $this->quote('id'),
                $this->quote('queue'),
                $this->quote('payload'),
                $this->quote('exception'),
                $this->quote('failed_at'),
                $this->quotedTable(),
                $this->quote('queue'),
                $this->quote('failed_at'),
            ),
            [$queue],
        )->rows();

        return array_map($this->hydrate(...), $rows);
    }

    public function find(string $id): ?FailedJob
    {
        $row = $this->connection->execute(
            sprintf(
                'SELECT %s, %s, %s, %s, %s FROM %s WHERE %s = ? LIMIT 1',
                $this->quote('id'),
                $this->quote('queue'),
                $this->quote('payload'),
                $this->quote('exception'),
                $this->quote('failed_at'),
                $this->quotedTable(),
                $this->quote('id'),
            ),
            [$id],
        )->first();

        return $row === null ? null : $this->hydrate($row);
    }

    public function delete(string $id): void
    {
        $this->connection->execute(
            sprintf('DELETE FROM %s WHERE %s = ?', $this->quotedTable(), $this->quote('id')),
            [$id],
        );
    }

    public function count(string $queue): int
    {
        $row = $this->connection->execute(
            sprintf(
                'SELECT COUNT(*) AS %s FROM %s WHERE %s = ?',
                $this->quote('total'),
                $this->quotedTable(),
                $this->quote('queue'),
            ),
            [$queue],
        )->first();

        return $row === null ? 0 : (int) $row['total'];
    }

    public function isEmpty(string $queue): bool
    {
        return $this->count($queue) === 0;
    }

    /**
     * Current time in the stored format, used when recording a failure.
     */
    public function now(): string
    {
        return ($this->clock)()->format('Y-m-d H:i:s');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): FailedJob
    {
        return new FailedJob(
            id: (string) $row['id'],
            queue: (string) $row['queue'],
            payload: (string) $row['payload'],
            exception: (string) $row['exception'],
            failedAt: (string) $row['failed_at'],
        );
    }

    private function quotedTable(): string
    {
        return $this->connection->driver()->quoteIdentifier($this->table);
    }

    private function quote(string $column): string
    {
        return $this->connection->driver()->quoteIdentifier($column);
    }
}
