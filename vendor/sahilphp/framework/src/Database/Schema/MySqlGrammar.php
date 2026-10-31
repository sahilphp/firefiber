<?php

declare(strict_types=1);

namespace Sahil\Database\Schema;

/**
 * MySQL/MariaDB DDL grammar.
 *
 * MySQL declares plain, unique and primary indexes inline in
 * `CREATE TABLE`; `TINYINT(1)` represents booleans (the closest thing to
 * a real boolean the server offers) and integers may be `UNSIGNED`.
 */
final class MySqlGrammar extends AbstractGrammar
{
    public function dialect(): string
    {
        return 'mysql';
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
            .' WHERE table_schema = DATABASE() AND table_name = ?',
            [$table],
        ];
    }

    protected function columnType(ColumnDefinition $column): string
    {
        $unsigned = $column->isUnsigned() ? ' UNSIGNED' : '';

        return match ($column->type()) {
            // Must mirror what id() renders, or MySQL refuses the foreign key.
            'id', 'foreignId' => 'BIGINT UNSIGNED',
            'bigInteger' => 'BIGINT'.$unsigned,
            'integer' => 'INT'.$unsigned,
            'string' => sprintf('VARCHAR(%d)', $column->lengthValue() ?? 255),
            'text' => 'TEXT',
            'boolean' => 'TINYINT(1)',
            // DATETIME matches the textual 'Y-m-d H:i:s' form the ORM writes.
            'timestamp', 'dateTime' => 'DATETIME',
            'decimal' => sprintf('DECIMAL(%d, %d)', $column->precisionValue() ?? 10, $column->scaleValue() ?? 2),
            'float' => 'DOUBLE',
            'json' => 'JSON',
            default => throw new SchemaException(sprintf('Unsupported MySQL column type [%s].', $column->type())),
        };
    }

    protected function inlineIndex(string $table, IndexDefinition $index): array
    {
        return match ($index->kind()) {
            IndexDefinition::KIND_PRIMARY => [sprintf(
                'PRIMARY KEY (%s)',
                $this->quoteList($index->columns()),
            )],
            IndexDefinition::KIND_UNIQUE => [sprintf(
                'UNIQUE KEY %s (%s)',
                $this->driver->quoteIdentifier($this->indexName($table, $index)),
                $this->quoteList($index->columns()),
            )],
            default => [sprintf(
                'INDEX %s (%s)',
                $this->driver->quoteIdentifier($this->indexName($table, $index)),
                $this->quoteList($index->columns()),
            )],
        };
    }

    /**
     * MySQL expresses every index kind inline, so a fresh table needs no
     * follow-up statements.
     *
     * @return list<string>
     */
    protected function postCreateStatements(Table $table): array
    {
        return [];
    }

    protected function alterIndex(string $table, IndexDefinition $index): string
    {
        $clause = match ($index->kind()) {
            IndexDefinition::KIND_PRIMARY => 'ADD PRIMARY KEY',
            IndexDefinition::KIND_UNIQUE => 'ADD UNIQUE KEY',
            default => 'ADD INDEX',
        };

        $name = $index->kind() === IndexDefinition::KIND_PRIMARY
            ? ''
            : $this->driver->quoteIdentifier($this->indexName($table, $index)).' ';

        return sprintf(
            'ALTER TABLE %s %s %s(%s)',
            $this->driver->quoteIdentifier($table),
            $clause,
            $name,
            $this->quoteList($index->columns()),
        );
    }

    protected function dropIndexStatement(string $table, string $index): string
    {
        return sprintf(
            'ALTER TABLE %s DROP INDEX %s',
            $this->driver->quoteIdentifier($table),
            $this->driver->quoteIdentifier($index),
        );
    }

    protected function dropForeignKeyStatement(string $table, string $foreignKey): string
    {
        return sprintf(
            'ALTER TABLE %s DROP FOREIGN KEY %s',
            $this->driver->quoteIdentifier($table),
            $this->driver->quoteIdentifier($foreignKey),
        );
    }

    protected function supportsUnsigned(): bool
    {
        return true;
    }

    protected function autoIncrementClause(): string
    {
        return 'AUTO_INCREMENT';
    }

    protected function booleanLiteral(bool $value): string
    {
        return $value ? '1' : '0';
    }

    /**
     * MySQL treats backslashes as escape characters inside string literals
     * (unless NO_BACKSLASH_ESCAPES is set), so both `\` and `'` are escaped.
     */
    protected function stringLiteral(string $value, string $column): string
    {
        if (str_contains($value, "\0")) {
            throw new SchemaException(sprintf('Default for column [%s] contains a NUL byte.', $column));
        }

        return "'".str_replace(['\\', "'"], ['\\\\', "''"], $value)."'";
    }
}
