<?php

declare(strict_types=1);

namespace Sahil\Scheduler;

use Sahil\Contracts\Scheduler\LockContract;
use Sahil\Database\Connection;
use Sahil\Database\ConnectionException;
use Sahil\Database\QueryException;

/**
 * Overlap prevention shared by every host that uses the same database.
 *
 * Uses the server's advisory lock (`GET_LOCK` on MySQL, `pg_try_advisory_lock`
 * on PostgreSQL) with a zero timeout, so a task that is already running on
 * another host is skipped instead of queued. The database releases the lock
 * when the session ends, so a crashed scheduler never leaves a task locked.
 *
 * MySQL's `GET_LOCK` namespace is global to the server; callers should
 * include the database name in `$prefix` (as `ScheduleProvider` does).
 */
final class DatabaseLock implements LockContract
{
    /** @var array<string, true> */
    private array $held = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly string $prefix = 'sahil_schedule',
    ) {
    }

    public function acquire(string $name): bool
    {
        $lockName = $this->lockName($name);

        if (isset($this->held[$lockName])) {
            return false;
        }

        [$sql, $parameters] = $this->connection->driver()->lockSql($lockName, 0);

        $row = $this->connection->execute($sql, $parameters)->first();

        if ($row === null) {
            return false;
        }

        if (!self::truthy(reset($row))) {
            return false;
        }

        $this->held[$lockName] = true;

        return true;
    }

    public function release(string $name): void
    {
        $lockName = $this->lockName($name);

        if (!isset($this->held[$lockName])) {
            return;
        }

        unset($this->held[$lockName]);

        [$sql, $parameters] = $this->connection->driver()->unlockSql($lockName);

        try {
            $this->connection->execute($sql, $parameters);
        } catch (QueryException|ConnectionException) {
            // Best effort: the server releases the advisory lock when the
            // session ends. Never mask the task's own outcome.
        }
    }

    public function holds(string $name): bool
    {
        return isset($this->held[$this->lockName($name)]);
    }

    private function lockName(string $name): string
    {
        return $this->prefix.':'.$name;
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
