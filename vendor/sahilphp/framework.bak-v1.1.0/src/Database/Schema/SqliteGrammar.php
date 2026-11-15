<?php

declare(strict_types=1);

namespace Sahil\Database\Schema;

/**
 * SQLite DDL grammar.
 *
 * SQLite is dynamically typed, so every column keeps a declared affinity
 * (`INTEGER`, `TEXT`, `VARCHAR(n)`, …) rather than a strict type. Two
 * dialect rules shape this grammar:
 *
 *  - an auto-incrementing key must be spelled `INTEGER PRIMARY KEY
 *    AUTOINCREMENT` in that order, so `id()` overrides the shared column
 *    compiler;
 *  - plain indexes cannot be declared inside `CREATE TABLE`, so they are
 *    emitted as `CREATE INDEX` statements afterwards, like PostgreSQL.
 *
 * There are no unsigned integer types and no `ALTER TABLE` support for
 * adding or dropping keys, so those requests fail with a clear message
 * instead of silently doing nothing.
 */
final class SqliteGrammar extends AbstractGrammar
{
    public function dialect(): string
    {
        return 'sqlite';
    }

    public function compileDrop(string $table, bool $ifExists): string
    {
        return sprintf(
            'DROP TABLE %s%s',
            $ifExists ? 'IF EXISTS ' : '',
            $this->driver->quoteIdentifier($table),
        );
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    public function compileTableExists(string $table): array
    {
        return [
            "SELECT 1 AS found FROM sqlite_master WHERE type = 'table' AND name = ?",
            [$table],
        ];
    }

    /**
     * The shared column compiler ends with `… AUTOINCREMENT PRIMARY KEY`,
     * which SQLite rejects; its rowid alias must be exactly
     * `INTEGER PRIMARY KEY AUTOINCREMENT`.
     */
    protected function compileColumn(ColumnDefinition $column): string
    {
        $type = $column->type();
        $autoIncrement = $column->isAutoIncrement() || $type === 'id';

        if (!$autoIncrement) {
            return parent::compileColumn($column);
        }

        return sprintf(
            '%s INTEGER PRIMARY KEY AUTOINCREMENT',
            $this->driver->quoteIdentifier($column->name()),
        );
    }

    protected function columnType(ColumnDefinition $column): string
    {
        return match ($column->type()) {
            'id', 'foreignId', 'bigInteger', 'integer' => 'INTEGER',
            'string' => sprintf('VARCHAR(%d)', $column->lengthValue() ?? 255),
            'text', 'longText', 'json' => 'TEXT',
            'boolean' => 'BOOLEAN',
            'timestamp', 'dateTime' => 'DATETIME',
            'decimal' => sprintf('DECIMAL(%d, %d)', $column->precisionValue() ?? 10, $column->scaleValue() ?? 2),
            'float' => 'DOUBLE',
            default => throw new SchemaException(sprintf('Unsupported SQLite column type [%s].', $column->type())),
        };
    }

    /**
     * Primary and unique constraints may be declared inline; a plain index
     * may not.
     */
    protected function inlineIndex(string $table, IndexDefinition $index): array
    {
        return match ($index->kind()) {
            IndexDefinition::KIND_PRIMARY => $this->inlinePrimary($table, $index),
            IndexDefinition::KIND_UNIQUE => $this->inlineUnique($table, $index),
            default => [],
        };
    }

    /**
     * @return list<string>
     */
    protected function postCreateStatements(Table $table): array
    {
        $statements = [];

        foreach ($table->indexes() as $index) {
            if ($index->kind() === IndexDefinition::KIND_INDEX) {
                $statements[] = $this->alterIndex($table->name(), $index);
            }
        }

        return $statements;
    }

    protected function alterIndex(string $table, IndexDefinition $index): string
    {
        return match ($index->kind()) {
            IndexDefinition::KIND_PRIMARY => throw new SchemaException(
                'SQLite cannot add a primary key to an existing table; declare it in Schema::create().',
            ),
            IndexDefinition::KIND_UNIQUE => sprintf(
                'CREATE UNIQUE INDEX %s ON %s (%s)',
                $this->driver->quoteIdentifier($this->indexName($table, $index)),
                $this->driver->quoteIdentifier($table),
                $this->quoteList($index->columns()),
            ),
            default => sprintf(
                'CREATE INDEX %s ON %s (%s)',
                $this->driver->quoteIdentifier($this->indexName($table, $index)),
                $this->driver->quoteIdentifier($table),
                $this->quoteList($index->columns()),
            ),
        };
    }

    protected function dropIndexStatement(string $table, string $index): string
    {
        return sprintf('DROP INDEX %s', $this->driver->quoteIdentifier($index));
    }

    protected function dropForeignKeyStatement(string $table, string $foreignKey): string
    {
        throw new SchemaException(
            'SQLite cannot drop a foreign key with ALTER TABLE; recreate the table instead.',
        );
    }

    protected function supportsUnsigned(): bool
    {
        return false;
    }

    protected function autoIncrementClause(): string
    {
        // Handled directly in compileColumn(): SQLite requires
        // `INTEGER PRIMARY KEY AUTOINCREMENT` as one clause.
        return '';
    }

    protected function booleanLiteral(bool $value): string
    {
        return $value ? '1' : '0';
    }

    /**
     * Standard SQL escaping: double the single quotes. Backslashes are
     * literal in SQLite string literals.
     */
    protected function stringLiteral(string $value, string $column): string
    {
        if (str_contains($value, "\0")) {
            throw new SchemaException(sprintf('Default for column [%s] contains a NUL byte.', $column));
        }

        return "'".str_replace("'", "''", $value)."'";
    }
}
