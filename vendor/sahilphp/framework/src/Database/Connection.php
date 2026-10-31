<?php

declare(strict_types=1);

namespace Sahil\Database;

use Closure;
use PDO;
use PDOException;
use Throwable;

/**
 * Thin, predictable PDO wrapper: everything user-controlled is a bound
 * parameter; raw SQL exists only via the explicit `Expression` object.
 *
 * Lifecycle:
 *  - connections are created eagerly on demand ({@see ConnectionFactory::make()}),
 *  - `disconnect()` frees the PDO handle after a rollback of whatever
 *    transaction was still open (worker-safe teardown),
 *  - `ping()` + `reconnect()` support long-running worker loops: call
 *    them between jobs — drivers torn down by idle timeouts otherwise
 *    throw on stale handles, and reconnecting drops open transactions
 *    by design.
 *  - `transaction()` is the preferred API: it always closes the
 *    transaction — commit on success, rollback (or savepoint rollback for
 *    nested scopes) on ANY throwable, with the original exception re-raised
 *    unchanged;
 *  - `beginTransaction()` / `commit()` / `rollBack()` exist for scopes that
 *    cannot be expressed as one callback (notably wrapping a whole test
 *    method); the caller is responsible for balancing them.
 */
final class Connection
{
    private ?PDO $pdo = null;

    private int $transactionDepth = 0;

    /**
     * @param Closure(array{dsn: string, username: ?string, password: ?string, options: array<int, mixed>}): PDO $pdoFactory
     */
    private function __construct(
        private readonly DriverContract $driver,
        private readonly string $dsn,
        private readonly ?string $username,
        private readonly ?string $password,
        private readonly Closure $pdoFactory,
    ) {
    }

    /**
     * Entry point used by `ConnectionFactory`; `$pdoFactory` may be
     * overridden in tests with an in-memory double.
     *
     * @param array<int, mixed> $options driver dialect options (factory supplies them)
     * @param Closure(array{dsn: string, username: ?string, password: ?string, options: array<int, mixed>}): PDO $pdoFactory
     */
    public static function connect(
        DriverContract $driver,
        string $dsn,
        ?string $username,
        ?string $password,
        array $options,
        Closure $pdoFactory,
    ): self {
        $self = new self($driver, $dsn, $username, $password, $pdoFactory);
        $self->establish($options);

        return $self;
    }

    /**
     * @param array<int, mixed> $options dialect PDO options
     */
    private function establish(array $options): void
    {
        try {
            /** @var PDO $pdo */
            $pdo = ($this->pdoFactory)([
                'dsn' => $this->dsn,
                'username' => $this->username,
                'password' => $this->password,
                'options' => $options,
            ]);
        } catch (PDOException $exception) {
            throw ConnectionException::withContext('Database connection failed', [
                'driver' => $this->driver->extension(),
                'reason' => preg_replace('/.*SQLSTATE\[.*?\] \[\d+\] */', '', $exception->getMessage()),
            ]);
        }

        $this->pdo = $pdo;
    }

    public function driver(): DriverContract
    {
        return $this->driver;
    }

    /**
     * Start a query builder against this connection.
     */
    public function table(string $table): QueryBuilder
    {
        return new QueryBuilder($this, $table);
    }

    /**
     * Execute a parameterized statement; values are ALWAYS bound, never
     * interpolated into the SQL text.
     *
     * @param array<string|int, mixed> $parameters
     */
    public function execute(string $sql, array $parameters = []): QueryResult
    {
        $pdo = $this->requirePdo();

        foreach ($parameters as $key => $parameter) {
            if (is_array($parameter)) {
                throw new \InvalidArgumentException(
                    sprintf('Binding arrays is not supported (parameter [%s]); expand manually.', $key),
                );
            }
        }

        try {
            /** @var \PDOStatement $statement */
            $statement = $pdo->prepare($sql);
        } catch (PDOException $exception) {
            throw new QueryException($sql, $parameters, $exception->getMessage());
        }

        try {
            $statement->execute($parameters);
        } catch (PDOException $exception) {
            throw new QueryException($sql, $parameters, $exception->getMessage());
        }

        return new QueryResult($statement, $pdo);
    }

    /**
     * Run `$operation` inside a transaction. Nesting uses savepoints
     * (MySQL/PostgreSQL); the innermost failure rolls back to its
     * savepoint and the outer exception propagates — the previous
     * exception chain is kept intact.
     *
     * @template T
     *
     * @param Closure(self): T $operation
     *
     * @return T
     */
    public function transaction(Closure $operation): mixed
    {
        if ($this->transactionDepth > 0) {
            if (!$this->driver->supportsSavepoints()) {
                throw ConnectionException::withContext('Driver does not support nested transactions', [
                    'driver' => $this->driver->extension(),
                ]);
            }
        }

        if ($this->transactionDepth === 0) {
            $this->requirePdo()->beginTransaction();
            $this->transactionDepth = 1;
        } else {
            $this->transactionDepth++;
            $this->execute('SAVEPOINT '.$this->savepointName());
        }

        $savepoint = $this->savepointName();

        try {
            $result = $operation($this);
        } catch (Throwable $failure) {
            $this->exitTransaction(false, $savepoint);
            throw $failure;
        }

        $this->exitTransaction(true, $savepoint);

        return $result;
    }

    /**
     * Begin a transaction the caller will close with {@see commit()} or
     * {@see rollBack()}.
     *
     * Prefer {@see transaction()} for work that fits in a callback: it
     * balances itself on every path. This low-level pair exists for scopes
     * that span multiple calls (test fixtures, long-running workers).
     */
    public function beginTransaction(): void
    {
        if ($this->transactionDepth === 0) {
            $this->requirePdo()->beginTransaction();
            $this->transactionDepth = 1;

            return;
        }

        if (!$this->driver->supportsSavepoints()) {
            throw ConnectionException::withContext('Driver does not support nested transactions', [
                'driver' => $this->driver->extension(),
            ]);
        }

        $this->transactionDepth++;
        $this->execute('SAVEPOINT '.$this->savepointName());
    }

    /**
     * Commit the innermost transaction begun with {@see beginTransaction()}
     * (or release its savepoint when nested).
     */
    public function commit(): void
    {
        if ($this->transactionDepth === 0) {
            throw new ConnectionException('There is no active transaction to commit.');
        }

        $this->exitTransaction(true, $this->savepointName());
    }

    /**
     * Roll back the innermost transaction begun with
     * {@see beginTransaction()} (or roll back to its savepoint when nested).
     */
    public function rollBack(): void
    {
        if ($this->transactionDepth === 0) {
            throw new ConnectionException('There is no active transaction to roll back.');
        }

        $this->exitTransaction(false, $this->savepointName());
    }

    /**
     * Commit (outer) or release savepoint (inner) on success. Rollback
     * failures never mask the originating exception (it was rethrown
     * before this runs on failure paths).
     */
    private function exitTransaction(bool $committed, string $savepoint): void
    {
        $outer = --$this->transactionDepth === 0;

        try {
            if ($outer) {
                if ($committed) {
                    $this->requirePdo()->commit();
                } else {
                    $this->requirePdo()->rollBack();
                }
            } elseif ($committed) {
                $this->execute('RELEASE SAVEPOINT '.$savepoint);
            } else {
                $this->execute('ROLLBACK TO SAVEPOINT '.$savepoint);
                $this->execute('RELEASE SAVEPOINT '.$savepoint);
            }
        } catch (PDOException|ConnectionException|QueryException $error) {
            throw ConnectionException::withContext('Transaction lifecycle failure', [
            'step' => $committed ? 'commit' : 'rollback',
                'reason' => $error->getMessage(),
            ]);
        }
    }

    private function savepointName(): string
    {
        return 'sahil_sp'.$this->transactionDepth;
    }

    /**
     * Introspection helper for tests and workers.
     */
    public function transactionDepth(): int
    {
        return $this->transactionDepth;
    }

    /**
     * Cheap probe for possibly-stale worker handles.
     */
    public function ping(): bool
    {
        try {
            $this->execute('SELECT 1');

            return true;
        } catch (ConnectionException|QueryException) {
            return false;
        }
    }

    /**
     * Free the PDO handle. Any still-open transaction is rolled back
     * first (best effort — the handle may already be dead).
     */
    public function disconnect(): void
    {
        if ($this->pdo !== null && $this->transactionDepth > 0) {
            try {
                $this->pdo->rollBack();
            } catch (PDOException) {
                // Teardown is best-effort; the handle is going away.
            }

            $this->transactionDepth = 0;
        }

        $this->pdo = null;
    }

    /**
     * Drop the handle and reconnect (loses open transactions — the
     * documented worker rule is to reconnect only between jobs).
     *
     * @param array<int, mixed> $options driver dialect options
     */
    public function reconnect(array $options): void
    {
        $this->disconnect();
        $this->establish($options);
    }

    public function isConnected(): bool
    {
        return $this->pdo !== null;
    }

    private function requirePdo(): PDO
    {
        if ($this->pdo === null) {
            throw ConnectionException::withContext('Database connection not available', [
                'driver' => $this->driver->extension(),
                'reason' => 'disconnected',
            ]);
        }

        return $this->pdo;
    }
}
