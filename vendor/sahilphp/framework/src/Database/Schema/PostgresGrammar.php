<?php

declare(strict_types=1);

namespace Sahil\Database\Schema;

/**
 * PostgreSQL DDL grammar.
 *
 * Two dialect differences are handled explicitly rather than papered over:
 *
 *  - PostgreSQL cannot declare a plain index inside `CREATE TABLE`, so
 *    those are emitted as separate `CREATE INDEX` statements;
 *  - there are no unsigned integer types, so `unsigned()` is REFUSED
 *    loudly instead of being silently dropped.
 */
final class PostgresGrammar extends AbstractGrammar
{
    public function dialect(): string
    {
        return 'pgsql';
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
            'SELECT 1 AS found FROM information_schema.tables'
            .' WHERE table_schema = current_schema() AND table_name = ?',
            [$table],
        ];
    }

    protected function columnType(ColumnDefinition $column): string
    {
        return match ($column->type()) {
            'id' => 'BIGSERIAL',
            // References an `id`-style column: BIGSERIAL is itself a BIGINT.
            'foreignId' => 'BIGINT',
            'bigInteger' => 'BIGINT',
            'integer' => 'INTEGER',
            'string' => sprintf('VARCHAR(%d)', $column->lengthValue() ?? 255),
            'text' => 'TEXT',
            'boolean' => 'BOOLEAN',
            'timestamp', 'dateTime' => 'TIMESTAMP',
            'decimal' => sprintf('DECIMAL(%d, %d)', $column->precisionValue() ?? 10, $column->scaleValue() ?? 2),
            'float' => 'DOUBLE PRECISION',
            'json' => 'JSONB',
            default => throw new SchemaException(sprintf('Unsupported PostgreSQL column type [%s].', $column->type())),
        };
    }

    /**
     * Unique and primary keys can be table constraints; a plain index
     * cannot be declared inside `CREATE TABLE` on PostgreSQL.
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
            IndexDefinition::KIND_PRIMARY => sprintf(
                'ALTER TABLE %s ADD CONSTRAINT %s PRIMARY KEY (%s)',
                $this->driver->quoteIdentifier($table),
                $this->driver->quoteIdentifier($this->indexName($table, $index)),
                $this->quoteList($index->columns()),
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
        return sprintf(
            'ALTER TABLE %s DROP CONSTRAINT %s',
            $this->driver->quoteIdentifier($table),
            $this->driver->quoteIdentifier($foreignKey),
        );
    }

    protected function supportsUnsigned(): bool
    {
        return false;
    }

    protected function autoIncrementClause(): string
    {
        // BIGSERIAL already implies the sequence-backed default.
        return '';
    }

    protected function booleanLiteral(bool $value): string
    {
        return $value ? 'TRUE' : 'FALSE';
    }

    /**
     * Standard SQL escaping: double the single quotes. Backslashes are
     * literal in ordinary PostgreSQL string literals.
     */
    protected function stringLiteral(string $value, string $column): string
    {
        if (str_contains($value, "\0")) {
            throw new SchemaException(sprintf('Default for column [%s] contains a NUL byte.', $column));
        }

        return "'".str_replace("'", "''", $value)."'";
    }
}
