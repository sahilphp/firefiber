<?php

declare(strict_types=1);

namespace Sahil\Database\Drivers;

use InvalidArgumentException;
use Sahil\Database\QuotingDriver;
use Sahil\Database\Schema\PostgresGrammar;
use Sahil\Database\Schema\SchemaGrammar;

/**
 * PostgreSQL dialect (uses the `pdo_pgsql` extension). Identifiers are
 * quoted with double quotes; user values are always bound, never inline.
 */
final class PostgresDriver extends QuotingDriver
{
    public function extension(): string
    {
        return 'pdo_pgsql';
    }

    /**
     * @param array<string, mixed> $configuration
     */
    public function dsn(array $configuration): string
    {
        $endpoint = $this->validatedEndpoint($configuration);
        $database = $configuration['database'] ?? null;

        if (!is_string($database) || $database === '') {
            throw new InvalidArgumentException('PostgreSQL configuration requires a database name.');
        }

        return sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            $endpoint['host'],
            $endpoint['port'],
            $database,
        );
    }

    /**
     * @param array<string, mixed> $configuration
     *
     * @return array<int, mixed>
     */
    public function pdoOptions(array $configuration): array
    {
        return [];
    }

    /** @return list<string> */
    protected function quoteMarks(): array
    {
        return ['"', '"'];
    }

    public function supportsSavepoints(): bool
    {
        return true;
    }

    public function grammar(): SchemaGrammar
    {
        return new PostgresGrammar($this);
    }

    /**
     * PostgreSQL DDL is transactional: a failed migration rolls back
     * cleanly, unlike MySQL/MariaDB.
     */
    public function supportsTransactionalDdl(): bool
    {
        return true;
    }

    /**
     * Advisory locks are scoped to the CURRENT DATABASE on PostgreSQL, so
     * the same name is safely independent across databases. The name is
     * folded into a signed-64-bit key (crc32 fits comfortably).
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public function lockSql(string $name, int $timeoutSeconds): array
    {
        return ['SELECT pg_try_advisory_lock(?) AS acquired', [self::lockKey($name)]];
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    public function unlockSql(string $name): array
    {
        return ['SELECT pg_advisory_unlock(?) AS released', [self::lockKey($name)]];
    }

    /**
     * Stable unsigned 32-bit key derived from the lock name; returned as an
     * int so it binds as a bigint parameter.
     */
    private static function lockKey(string $name): int
    {
        return crc32($name);
    }
}
