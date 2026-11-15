<?php

declare(strict_types=1);

namespace Sahil\Database\Schema;

use Closure;
use Sahil\Database\Connection;
use Sahil\Database\Identifier;

/**
 * Driver-agnostic schema building on top of a {@see Connection}.
 *
 * ```php
 * $schema->create('users', function (Table $table): void {
 *     $table->id();
 *     $table->string('email', 190)->unique();
 *     $table->timestamps();
 * });
 *
 * $schema->table('users', function (Table $table): void {
 *     $table->string('nickname', 60)->nullable();
 * });
 *
 * $schema->dropIfExists('users');
 * ```
 *
 * DDL statements are produced by the connection's dialect grammar, so the
 * same migration runs on MySQL and PostgreSQL. Identifiers are validated
 * before any SQL is built; literal defaults are escaped by the grammar
 * (DDL cannot take bound parameters) and never contain request data.
 */
final class Schema
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param Closure(Table): void $definition
     */
    public function create(string $table, Closure $definition): void
    {
        $this->run($this->compile(Table::CREATE, $table, $definition));
    }

    /**
     * `CREATE TABLE IF NOT EXISTS` — lets a migration be re-run on a
     * database that already has the table.
     *
     * @param Closure(Table): void $definition
     */
    public function createIfNotExists(string $table, Closure $definition): void
    {
        $blueprint = $this->blueprint(Table::CREATE, $table, $definition)->ifNotExists();

        $this->run($this->grammar()->compileCreate($blueprint));
    }

    /**
     * Alter an existing table (add columns, indexes, foreign keys, or drop
     * explicit ones).
     *
     * @param Closure(Table): void $definition
     */
    public function table(string $table, Closure $definition): void
    {
        $this->run($this->compile(Table::ALTER, $table, $definition));
    }

    public function drop(string $table): void
    {
        Identifier::segments($table);

        $this->connection->execute($this->grammar()->compileDrop($table, false));
    }

    public function dropIfExists(string $table): void
    {
        Identifier::segments($table);

        $this->connection->execute($this->grammar()->compileDrop($table, true));
    }

    /**
     * Whether the table exists in the connection's current schema. The
     * table name travels as a bound parameter.
     */
    public function hasTable(string $table): bool
    {
        Identifier::segments($table);

        [$sql, $parameters] = $this->grammar()->compileTableExists($table);

        return $this->connection->execute($sql, $parameters)->rows() !== [];
    }

    /**
     * The dialect this schema is bound to (`mysql` or `pgsql`).
     */
    public function dialect(): string
    {
        return $this->grammar()->dialect();
    }

    /**
     * @param Closure(Table): void $definition
     *
     * @return list<string>
     */
    private function compile(string $mode, string $table, Closure $definition): array
    {
        $blueprint = $this->blueprint($mode, $table, $definition);

        return $blueprint->isCreate()
            ? $this->grammar()->compileCreate($blueprint)
            : $this->grammar()->compileAlter($blueprint);
    }

    /**
     * @param Closure(Table): void $definition
     */
    private function blueprint(string $mode, string $table, Closure $definition): Table
    {
        Identifier::segments($table);

        $blueprint = new Table($table, $mode);
        $definition($blueprint);

        return $blueprint;
    }

    /**
     * @param list<string> $statements
     */
    private function run(array $statements): void
    {
        foreach ($statements as $statement) {
            $this->connection->execute($statement);
        }
    }

    private function grammar(): SchemaGrammar
    {
        return $this->connection->driver()->grammar();
    }
}
