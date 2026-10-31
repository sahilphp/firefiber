<?php

declare(strict_types=1);

namespace Sahil\Database\Schema;

use Sahil\Database\DriverContract;
use Sahil\Database\Expression;
use Sahil\Database\Identifier;

/**
 * Shared DDL compilation: literal quoting, deterministic constraint-name
 * derivation, and the create/alter skeletons. Concrete grammars supply
 * only the pieces that genuinely differ (column types, index statements,
 * drop syntax and unsigned-integer support).
 */
abstract class AbstractGrammar implements SchemaGrammar
{
    public function __construct(protected readonly DriverContract $driver)
    {
    }

    public function compileCreate(Table $table): array
    {
        if (!$table->isCreate()) {
            throw new SchemaException('compileCreate() requires a table blueprint in create mode.');
        }

        if ($table->columns() === []) {
            throw new SchemaException(sprintf('Table [%s] declares no columns.', $table->name()));
        }

        $definitions = [];

        foreach ($table->columns() as $column) {
            $definitions[] = $this->compileColumn($column);
        }

        foreach ($table->indexes() as $index) {
            // Plain indexes are not declarable inline in every dialect; the
            // concrete grammar decides (empty result = emitted separately).
            foreach ($this->inlineIndex($table->name(), $index) as $fragment) {
                $definitions[] = $fragment;
            }
        }

        foreach ($table->foreignKeys() as $foreignKey) {
            $foreignKey->assertComplete();
            $definitions[] = $this->compileForeignKey($table->name(), $foreignKey);
        }

        $sql = sprintf(
            'CREATE TABLE %s%s (%s)',
            $table->wantsIfNotExists() ? 'IF NOT EXISTS ' : '',
            $this->driver->quoteIdentifier($table->name()),
            implode(', ', $definitions),
        );

        return [$sql, ...$this->postCreateStatements($table)];
    }

    /**
     * Statements that apply changes to an existing table.
     *
     * Order matters and is fixed: foreign keys are dropped, then indexes,
     * then columns, before anything is added. MySQL silently drops a
     * single-column index together with its column and refuses to drop a
     * column still referenced by a foreign key, so removing constraints
     * AFTER the column would fail — and on a driver that cannot roll DDL
     * back that failure would be unrecoverable.
     */
    public function compileAlter(Table $table): array
    {
        if ($table->isCreate()) {
            throw new SchemaException('compileAlter() requires a table blueprint in alter mode.');
        }

        $quotedTable = $this->driver->quoteIdentifier($table->name());
        $statements = [];

        foreach ($table->droppedForeignKeys() as $foreignKey) {
            $statements[] = $this->dropForeignKeyStatement($table->name(), $foreignKey);
        }

        foreach ($table->droppedIndexes() as $index) {
            $statements[] = $this->dropIndexStatement($table->name(), $index);
        }

        foreach ($table->droppedColumns() as $column) {
            $statements[] = sprintf(
                'ALTER TABLE %s DROP COLUMN %s',
                $quotedTable,
                $this->driver->quoteIdentifier($column),
            );
        }

        foreach ($table->columns() as $column) {
            $statements[] = sprintf(
                'ALTER TABLE %s ADD COLUMN %s',
                $quotedTable,
                $this->compileColumn($column),
            );
        }

        foreach ($table->indexes() as $index) {
            $statements[] = $this->alterIndex($table->name(), $index);
        }

        foreach ($table->foreignKeys() as $foreignKey) {
            $foreignKey->assertComplete();
            $statements[] = sprintf(
                'ALTER TABLE %s ADD %s',
                $quotedTable,
                $this->compileForeignKey($table->name(), $foreignKey),
            );
        }

        if ($statements === []) {
            throw new SchemaException(sprintf(
                'Alteration of table [%s] changes nothing; remove the empty Schema::table() call.',
                $table->name(),
            ));
        }

        return $statements;
    }

    /* ---------- dialect hooks ---------- */

    /**
     * Dialect column type (without nullability/defaults).
     */
    abstract protected function columnType(ColumnDefinition $column): string;

    /**
     * Inline index fragments valid inside `CREATE TABLE`; dialects that
     * cannot express a kind inline return an empty list and emit the
     * statement in {@see postCreateStatements()} instead.
     *
     * @return list<string>
     */
    abstract protected function inlineIndex(string $table, IndexDefinition $index): array;

    /**
     * @return list<string>
     */
    abstract protected function postCreateStatements(Table $table): array;

    abstract protected function alterIndex(string $table, IndexDefinition $index): string;

    abstract protected function dropIndexStatement(string $table, string $index): string;

    abstract protected function dropForeignKeyStatement(string $table, string $foreignKey): string;

    /**
     * Whether the dialect has unsigned integer types.
     */
    abstract protected function supportsUnsigned(): bool;

    /**
     * How the dialect spells an auto-incrementing column.
     */
    abstract protected function autoIncrementClause(): string;

    /**
     * Dialect boolean literal (MySQL `1`/`0`, PostgreSQL `TRUE`/`FALSE`).
     */
    abstract protected function booleanLiteral(bool $value): string;

    /**
     * Quote a string literal with the dialect's escaping rules.
     */
    abstract protected function stringLiteral(string $value, string $column): string;

    /* ---------- shared compilation ---------- */

    protected function compileColumn(ColumnDefinition $column): string
    {
        if ($column->isUnsigned() && !$this->supportsUnsigned()) {
            throw new SchemaException(sprintf(
                'The [%s] dialect has no unsigned integer types; drop unsigned() on column [%s].',
                $this->dialect(),
                $column->name(),
            ));
        }

        $type = $column->type();
        $autoIncrement = $column->isAutoIncrement() || $type === 'id';
        $primary = $column->isPrimary() || $type === 'id';

        $parts = [$this->driver->quoteIdentifier($column->name()), $this->columnType($column)];

        // NOT NULL / NULL — an auto-increment or primary key column is
        // implicitly required, so it never gets a NULL clause.
        if ($primary || $autoIncrement) {
            $parts[] = 'NOT NULL';
        } else {
            $parts[] = $column->isNullable() ? 'NULL' : 'NOT NULL';
        }

        if ($column->hasDefault()) {
            $parts[] = 'DEFAULT '.$this->compileLiteral($column->defaultValue(), $column->name());
        }

        if ($autoIncrement) {
            $parts[] = $this->autoIncrementClause();
        }

        if ($primary) {
            $parts[] = 'PRIMARY KEY';
        }

        if ($column->isUnique() && !$primary) {
            $parts[] = 'UNIQUE';
        }

        return implode(' ', array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    protected function compileForeignKey(string $table, ForeignKeyDefinition $foreignKey): string
    {
        $fragment = sprintf(
            'CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (%s)',
            $this->driver->quoteIdentifier($this->foreignKeyName($table, $foreignKey)),
            $this->driver->quoteIdentifier($foreignKey->column()),
            $this->driver->quoteIdentifier((string) $foreignKey->referencedTable()),
            $this->driver->quoteIdentifier((string) $foreignKey->referencedColumn()),
        );

        if ($foreignKey->deleteAction() !== null) {
            $fragment .= ' ON DELETE '.$foreignKey->deleteAction();
        }

        if ($foreignKey->updateAction() !== null) {
            $fragment .= ' ON UPDATE '.$foreignKey->updateAction();
        }

        return $fragment;
    }

    /**
     * Quote and escape a DDL literal. DDL cannot take bound parameters, so
     * literal defaults are escaped here and are restricted to scalars
     * (enforced by {@see ColumnDefinition::default()}), with the raw-SQL
     * form available only through an explicit `Expression`.
     */
    protected function compileLiteral(mixed $value, string $column): string
    {
        if ($value instanceof Expression) {
            return $value->sql;
        }

        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $this->booleanLiteral($value);
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            if (is_nan($value) || is_infinite($value)) {
                throw new SchemaException(sprintf(
                    'Default for column [%s] is not a finite number.',
                    $column,
                ));
            }

            $encoded = json_encode($value);

            return $encoded === false ? '0' : $encoded;
        }

        return $this->stringLiteral($value, $column);
    }

    /**
     * @return list<string>
     */
    protected function inlineUnique(string $table, IndexDefinition $index): array
    {
        return [sprintf(
            'CONSTRAINT %s UNIQUE (%s)',
            $this->driver->quoteIdentifier($this->indexName($table, $index)),
            $this->quoteList($index->columns()),
        )];
    }

    /**
     * @return list<string>
     */
    protected function inlinePrimary(string $table, IndexDefinition $index): array
    {
        return [sprintf(
            'CONSTRAINT %s PRIMARY KEY (%s)',
            $this->driver->quoteIdentifier($this->indexName($table, $index)),
            $this->quoteList($index->columns()),
        )];
    }

    /**
     * @param list<string> $columns
     */
    protected function quoteList(array $columns): string
    {
        return implode(', ', array_map(
            fn (string $column): string => $this->driver->quoteIdentifier($column),
            $columns,
        ));
    }

    protected function indexName(string $table, IndexDefinition $index): string
    {
        if ($index->name() !== null) {
            return $index->name();
        }

        return $this->deriveName($table, $index->columns(), $index->kind());
    }

    protected function foreignKeyName(string $table, ForeignKeyDefinition $foreignKey): string
    {
        return $foreignKey->explicitName() ?? $this->deriveName($table, [$foreignKey->column()], 'foreign');
    }

    /**
     * Deterministic, dialect-legal constraint name: `{table}_{columns}_{kind}`,
     * shortened with a hash suffix when it would exceed the identifier limit.
     *
     * @param list<string> $columns
     */
    private function deriveName(string $table, array $columns, string $kind): string
    {
        $flat = implode('_', array_map(
            static fn (string $column): string => str_replace('.', '_', $column),
            $columns,
        ));

        $candidate = str_replace('.', '_', $table).'_'.$flat.'_'.$kind;
        $candidate = (string) preg_replace('/[^A-Za-z0-9_]/', '_', $candidate);

        if (strlen($candidate) <= Identifier::MAX_SEGMENT_LENGTH) {
            return $candidate;
        }

        $suffix = substr(sha1($candidate), 0, 8);

        return substr($candidate, 0, Identifier::MAX_SEGMENT_LENGTH - strlen($suffix) - 1).'_'.$suffix;
    }
}
