<?php

declare(strict_types=1);

namespace Sahil\Migrations;

use Closure;
use Sahil\Database\Connection;
use Sahil\Database\ConnectionException;
use Sahil\Database\QueryException;

/**
 * Concurrency safeguard: only one process may run migrations at a time.
 *
 * Backed by the database's own advisory lock (`GET_LOCK` on MySQL,
 * `pg_try_advisory_lock` on PostgreSQL) rather than a filesystem or ledger
 * flag, so a crashed process can never leave a lock stuck — the server
 * releases it when the session ends.
 *
 * Scope differs per server and is handled by the caller:
 *  - PostgreSQL advisory locks are per DATABASE;
 *  - MySQL's `GET_LOCK` namespace is GLOBAL to the server, so the lock name
 *    must include the database name to keep separate databases independent.
 *
 * Release is best-effort: if it fails the lock disappears with the session.
 */
final class MigrationLock
{
    public function __construct(
        private readonly Connection $connection,
        private string $name = 'sahil_migrations',
        private readonly int $timeoutSeconds = 10,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * Try to take the lock without blocking indefinitely.
     */
    public function acquire(): bool
    {
        [$sql, $parameters] = $this->connection->driver()->lockSql($this->name, $this->timeoutSeconds);

        $rows = $this->connection->execute($sql, $parameters)->rows();
        $first = $rows[0] ?? null;

        if ($first === null) {
            return false;
        }

        return self::truthy(reset($first));
    }

    /**
     * Release the lock (safe to call when it is not held).
     */
    public function release(): void
    {
        [$sql, $parameters] = $this->connection->driver()->unlockSql($this->name);

        try {
            $this->connection->execute($sql, $parameters);
        } catch (QueryException|ConnectionException) {
            // Best effort only: the advisory lock is released by the server
            // when this session ends. Never mask the caller's real outcome.
        }
    }

    /**
     * Run `$work` while holding the lock, releasing it in a `finally` block
     * so an exception can never leave the lock held for the session.
     *
     * @template T
     *
     * @param Closure(): T $work
     *
     * @return T
     *
     * @throws MigrationException when another process holds the lock
     */
    public function synchronized(Closure $work): mixed
    {
        if (!$this->acquire()) {
            throw new MigrationException(sprintf(
                'Another process is running migrations (advisory lock [%s] is held). Wait for it to finish and retry.',
                $this->name,
            ));
        }

        try {
            return $work();
        } finally {
            $this->release();
        }
    }

    /**
     * Normalise the acquired flag across drivers: PostgreSQL returns a
     * boolean, MySQL returns 1/0, and both may arrive as strings.
     */
    private static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        if ($value === null) {
            return false;
        }

        $text = strtolower(trim((string) $value));

        return !in_array($text, ['', '0', 'f', 'false', 'off', 'no', 'null'], true);
    }
}
