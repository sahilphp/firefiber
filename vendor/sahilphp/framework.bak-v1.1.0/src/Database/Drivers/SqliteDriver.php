<?php

declare(strict_types=1);

namespace Sahil\Database\Drivers;

use InvalidArgumentException;
use Sahil\Database\QuotingDriver;
use Sahil\Database\Schema\SchemaGrammar;
use Sahil\Database\Schema\SqliteGrammar;

/**
 * SQLite dialect (uses the `pdo_sqlite` extension).
 *
 * SQLite is the zero-setup default: the database is a single file, the DSN
 * is just its path, and there is no server, user or password to configure.
 * Identifiers are quoted with double quotes; user values are always bound.
 */
final class SqliteDriver extends QuotingDriver
{
    public function extension(): string
    {
        return 'pdo_sqlite';
    }

    /**
     * @param array<string, mixed> $configuration
     */
    public function dsn(array $configuration): string
    {
        $database = $configuration['database'] ?? null;

        if (!is_string($database) || trim($database) === '') {
            throw new InvalidArgumentException(
                'SQLite configuration requires a database file path (for example database/database.sqlite) or :memory:.',
            );
        }

        return 'sqlite:'.$database;
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
        return new SqliteGrammar($this);
    }

    /**
     * SQLite DDL is transactional, like PostgreSQL.
     */
    public function supportsTransactionalDdl(): bool
    {
        return true;
    }

    /**
     * SQLite has no advisory locks. Its single-writer model already
     * serialises DDL, so migrations proceed without a server-side lock.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public function lockSql(string $name, int $timeoutSeconds): array
    {
        return ['SELECT 1 AS acquired', []];
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    public function unlockSql(string $name): array
    {
        return ['SELECT 1 AS released', []];
    }
}
