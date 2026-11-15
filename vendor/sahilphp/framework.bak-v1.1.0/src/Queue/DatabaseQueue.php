<?php

declare(strict_types=1);

namespace Sahil\Queue;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Sahil\Contracts\Queue\QueueContract;
use Sahil\Contracts\Queue\QueuedJob;
use Sahil\Database\Connection;
use Sahil\Database\Identifier;

/**
 * Database-backed queue.
 *
 * Jobs live in one table so they survive a process restart and can be
 * inspected with plain SQL. Reservation is a short transaction: the oldest
 * ready row is locked, `attempts` is incremented and the row is marked
 * reserved, so two workers can never take the same job.
 *
 * The table is created by the shipped migrations; the store never creates
 * schema at runtime. Timestamps are stored as `Y-m-d H:i:s` strings, the
 * same representation the rest of the framework uses, which keeps the
 * comparison portable between MySQL and PostgreSQL.
 */
final class DatabaseQueue implements QueueContract
{
    private readonly string $table;

    /** @var Closure(): DateTimeImmutable */
    private readonly Closure $clock;

    /**
     * @param Closure(): DateTimeImmutable|null $clock
     */
    public function __construct(
        private readonly Connection $connection,
        string $table = 'jobs',
        ?Closure $clock = null,
    ) {
        Identifier::segments($table);

        $this->table = $table;
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function push(string $queue, string $payload, int $delay = 0): string
    {
        $id = bin2hex(random_bytes(16));

        $this->connection->execute(
            sprintf(
                'INSERT INTO %s (%s, %s, %s, %s, %s, %s) VALUES (?, ?, ?, ?, ?, ?)',
                $this->quotedTable(),
                $this->quote('id'),
                $this->quote('queue'),
                $this->quote('payload'),
                $this->quote('attempts'),
                $this->quote('available_at'),
                $this->quote('created_at'),
            ),
            [$id, $queue, $payload, 0, $this->availableAt($delay), $this->now()],
        );

        return $id;
    }

    public function later(string $queue, string $payload, int $delay): string
    {
        return $this->push($queue, $payload, $delay);
    }

    public function pop(string $queue): ?QueuedJob
    {
        return $this->connection->transaction(function () use ($queue): ?QueuedJob {
            $now = $this->now();

            $row = $this->connection->execute(
                sprintf(
                    'SELECT %s, %s, %s FROM %s WHERE %s = ? AND %s IS NULL AND %s <= ?'
                    .' ORDER BY %s ASC, %s ASC LIMIT 1 FOR UPDATE',
                    $this->quote('id'),
                    $this->quote('payload'),
                    $this->quote('attempts'),
                    $this->quotedTable(),
                    $this->quote('queue'),
                    $this->quote('reserved_at'),
                    $this->quote('available_at'),
                    $this->quote('available_at'),
                    $this->quote('id'),
                ),
                [$queue, $now],
            )->first();

            if ($row === null) {
                return null;
            }

            $affected = $this->connection->execute(
                sprintf(
                    'UPDATE %s SET %s = ?, %s = %s + 1 WHERE %s = ? AND %s IS NULL',
                    $this->quotedTable(),
                    $this->quote('reserved_at'),
                    $this->quote('attempts'),
                    $this->quote('attempts'),
                    $this->quote('id'),
                    $this->quote('reserved_at'),
                ),
                [$now, (string) $row['id']],
            )->affected();

            if ($affected !== 1) {
                return null;
            }

            return new QueuedJob(
                id: (string) $row['id'],
                queue: $queue,
                payload: (string) $row['payload'],
                attempts: (int) $row['attempts'] + 1,
            );
        });
    }

    public function delete(string $id): void
    {
        $this->connection->execute(
            sprintf('DELETE FROM %s WHERE %s = ?', $this->quotedTable(), $this->quote('id')),
            [$id],
        );
    }

    public function release(string $id, int $delay = 0): void
    {
        $this->connection->execute(
            sprintf(
                'UPDATE %s SET %s = NULL, %s = ? WHERE %s = ?',
                $this->quotedTable(),
                $this->quote('reserved_at'),
                $this->quote('available_at'),
                $this->quote('id'),
            ),
            [$this->availableAt($delay), $id],
        );
    }

    public function size(string $queue): int
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
        $row = $this->connection->execute(
            sprintf(
                'SELECT %s FROM %s WHERE %s = ? AND %s IS NULL AND %s <= ? LIMIT 1',
                $this->quote('id'),
                $this->quotedTable(),
                $this->quote('queue'),
                $this->quote('reserved_at'),
                $this->quote('available_at'),
            ),
            [$queue, $this->now()],
        )->first();

        return $row === null;
    }

    public function table(): string
    {
        return $this->table;
    }

    private function quotedTable(): string
    {
        return $this->connection->driver()->quoteIdentifier($this->table);
    }

    private function quote(string $column): string
    {
        return $this->connection->driver()->quoteIdentifier($column);
    }

    private function now(): string
    {
        return ($this->clock)()->format('Y-m-d H:i:s');
    }

    private function availableAt(int $delay): string
    {
        $at = ($this->clock)();

        return $delay > 0 ? $at->modify(sprintf('+%d seconds', $delay))->format('Y-m-d H:i:s') : $at->format('Y-m-d H:i:s');
    }
}
