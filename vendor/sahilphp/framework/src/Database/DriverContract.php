<?php

declare(strict_types=1);

namespace Sahil\Database;

use Sahil\Database\Schema\SchemaGrammar;

/**
 * A dialect adapter. Implementations own every piece of vendor SQL text:
 * DSN assembly, identifier quoting, savepoint names, schema (DDL) grammar,
 * advisory-lock statements and whatever else the driver guarantees.
 */
interface DriverContract
{
    /** PHP extension short name, e.g. `pdo_mysql`. */
    public function extension(): string;

    /**
     * Build the PDO DSN from (already validated) config entries.
     *
     * @param array<string, mixed> $configuration
     */
    public function dsn(array $configuration): string;

    /**
     * PDO options needed by this dialect (error mode is set by the
     * connection itself).
     *
     * @param array<string, mixed> $configuration
     *
     * @return array<int, mixed>
     */
    public function pdoOptions(array $configuration): array;

    /**
     * Quote a validated dotted identifier per dialect.
     *
     * @throws \InvalidArgumentException on unsupported identifiers
     */
    public function quoteIdentifier(string $identifier): string;

    public function supportsSavepoints(): bool;

    /**
     * The DDL grammar for this dialect (schema building / migrations).
     */
    public function grammar(): SchemaGrammar;

    /**
     * Whether DDL participates in transactions. When true, a failed
     * migration's schema changes roll back with the transaction; when
     * false (MySQL/MariaDB — DDL causes an implicit commit) a partially
     * applied migration must be reported to the operator instead.
     */
    public function supportsTransactionalDdl(): bool;

    /**
     * SQL that tries to take a named advisory lock without blocking.
     *
     * The statement must return a truthy value when the lock was acquired
     * and a falsy value when another session holds it. `$timeoutSeconds`
     * is honoured where the dialect supports waiting.
     *
     * Note the scope differences: PostgreSQL advisory locks are scoped to
     * the current database, while MySQL's `GET_LOCK` namespace is GLOBAL to
     * the server — pass a name that includes the database name there.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public function lockSql(string $name, int $timeoutSeconds): array;

    /**
     * SQL that releases a lock taken by {@see lockSql()}.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public function unlockSql(string $name): array;
}
