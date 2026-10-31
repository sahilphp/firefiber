<?php

declare(strict_types=1);

namespace Sahil\Database\Schema;

use Sahil\Database\Identifier;

/**
 * Fluent blueprint for one table, in one of two modes:
 *
 *  - {@see self::CREATE} — `Schema::create()`: columns are declared, and
 *    indexes/foreign keys are emitted inside the `CREATE TABLE` statement
 *    where the dialect allows it;
 *  - {@see self::ALTER} — `Schema::table()`: columns are added, and
 *    indexes/foreign keys become separate `ALTER TABLE` statements;
 *    `dropColumn()`, `dropIndex()` and `dropForeign()` are only valid here.
 *
 * Blueprints collect intent only. Every piece of SQL text is produced by
 * the driver's grammar, so the same migration body runs on both supported
 * drivers.
 *
 * ```php
 * $schema->create('users', function (Table $table): void {
 *     $table->id();
 *     $table->string('name', 120);
 *     $table->string('email', 190)->unique();
 *     $table->boolean('active')->default(true);
 *     $table->timestamps();
 *     $table->foreign('team_id')->references('id')->on('teams')->onDelete('cascade');
 *     $table->index(['email', 'name']);
 * });
 * ```
 */
final class Table
{
    public const CREATE = 'create';

    public const ALTER = 'alter';

    /** @var list<ColumnDefinition> */
    private array $columns = [];

    /** @var list<IndexDefinition> */
    private array $indexes = [];

    /** @var list<ForeignKeyDefinition> */
    private array $foreignKeys = [];

    /** @var list<string> */
    private array $dropColumns = [];

    /** @var list<string> */
    private array $dropIndexes = [];

    /** @var list<string> */
    private array $dropForeignKeys = [];

    private bool $ifNotExists = false;

    public function __construct(
        private readonly string $name,
        private readonly string $mode,
    ) {
        Identifier::segments($name);

        if (!in_array($mode, [self::CREATE, self::ALTER], true)) {
            throw new SchemaException(sprintf('Unsupported table mode [%s].', $mode));
        }
    }

    /* ---------- columns ---------- */

    /**
     * Auto-incrementing big-integer primary key.
     */
    public function id(string $column = 'id'): ColumnDefinition
    {
        return $this->addColumn(new ColumnDefinition($column, 'id'));
    }

    /**
     * Column type that EXACTLY matches what {@see id()} renders for the
     * dialect — MySQL requires a foreign key column to have an identical
     * type to the column it references, so pairing `id()` with `foreignId()`
     * is the portable way to declare a relationship:
     *
     * ```php
     * $table->foreignId('team_id')->nullable();
     * $table->foreign('team_id')->references('id')->on('teams')->onDelete('cascade');
     * ```
     *
     * (`bigInteger()` is signed on MySQL and would be rejected as a foreign
     * key against an unsigned `id()`.)
     */
    public function foreignId(string $column): ColumnDefinition
    {
        return $this->addColumn(new ColumnDefinition($column, 'foreignId'));
    }

    public function bigInteger(string $column): ColumnDefinition
    {
        return $this->addColumn(new ColumnDefinition($column, 'bigInteger'));
    }

    public function integer(string $column): ColumnDefinition
    {
        return $this->addColumn(new ColumnDefinition($column, 'integer'));
    }

    public function string(string $column, int $length = 255): ColumnDefinition
    {
        return $this->addColumn((new ColumnDefinition($column, 'string'))->length($length));
    }

    public function text(string $column): ColumnDefinition
    {
        return $this->addColumn(new ColumnDefinition($column, 'text'));
    }

    public function boolean(string $column): ColumnDefinition
    {
        return $this->addColumn(new ColumnDefinition($column, 'boolean'));
    }

    public function timestamp(string $column): ColumnDefinition
    {
        return $this->addColumn(new ColumnDefinition($column, 'timestamp'));
    }

    public function dateTime(string $column): ColumnDefinition
    {
        return $this->addColumn(new ColumnDefinition($column, 'dateTime'));
    }

    public function decimal(string $column, int $precision = 10, int $scale = 2): ColumnDefinition
    {
        return $this->addColumn((new ColumnDefinition($column, 'decimal'))->precision($precision, $scale));
    }

    public function float(string $column): ColumnDefinition
    {
        return $this->addColumn(new ColumnDefinition($column, 'float'));
    }

    public function json(string $column): ColumnDefinition
    {
        return $this->addColumn(new ColumnDefinition($column, 'json'));
    }

    /**
     * Nullable `created_at` / `updated_at` pair. Nullable (not
     * `DEFAULT CURRENT_TIMESTAMP`) so the column behaves identically on
     * both supported drivers; the ORM supplies the values explicitly.
     */
    public function timestamps(): self
    {
        $this->timestamp('created_at')->nullable();
        $this->timestamp('updated_at')->nullable();

        return $this;
    }

    private function addColumn(ColumnDefinition $column): ColumnDefinition
    {
        $this->columns[] = $column;

        return $column;
    }

    /* ---------- indexes ---------- */

    /**
     * @param string|list<string> $columns
     */
    public function index(string|array $columns, ?string $name = null): self
    {
        $this->indexes[] = new IndexDefinition(self::normaliseColumns($columns), IndexDefinition::KIND_INDEX, $name);

        return $this;
    }

    /**
     * @param string|list<string> $columns
     */
    public function unique(string|array $columns, ?string $name = null): self
    {
        $this->indexes[] = new IndexDefinition(self::normaliseColumns($columns), IndexDefinition::KIND_UNIQUE, $name);

        return $this;
    }

    /**
     * Composite primary key; a single `id()` column already carries its own.
     *
     * @param string|list<string> $columns
     */
    public function primary(string|array $columns, ?string $name = null): self
    {
        $this->indexes[] = new IndexDefinition(self::normaliseColumns($columns), IndexDefinition::KIND_PRIMARY, $name);

        return $this;
    }

    /* ---------- foreign keys ---------- */

    public function foreign(string $column): ForeignKeyDefinition
    {
        $foreignKey = new ForeignKeyDefinition($column);
        $this->foreignKeys[] = $foreignKey;

        return $foreignKey;
    }

    /* ---------- destructive table changes (ALTER mode only) ---------- */

    public function dropColumn(string ...$columns): self
    {
        $this->assertAlterMode('dropColumn');

        foreach ($columns as $column) {
            Identifier::segments($column);
            $this->dropColumns[] = $column;
        }

        return $this;
    }

    public function dropIndex(string ...$names): self
    {
        $this->assertAlterMode('dropIndex');

        foreach ($names as $name) {
            Identifier::segments($name);
            $this->dropIndexes[] = $name;
        }

        return $this;
    }

    public function dropForeign(string ...$names): self
    {
        $this->assertAlterMode('dropForeign');

        foreach ($names as $name) {
            Identifier::segments($name);
            $this->dropForeignKeys[] = $name;
        }

        return $this;
    }

    /**
     * `CREATE TABLE IF NOT EXISTS` instead of `CREATE TABLE`.
     */
    public function ifNotExists(): self
    {
        $this->ifNotExists = true;

        return $this;
    }

    /* ---------- grammar-facing accessors ---------- */

    public function name(): string
    {
        return $this->name;
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function isCreate(): bool
    {
        return $this->mode === self::CREATE;
    }

    public function wantsIfNotExists(): bool
    {
        return $this->ifNotExists;
    }

    /**
     * @return list<ColumnDefinition>
     */
    public function columns(): array
    {
        return $this->columns;
    }

    /**
     * @return list<IndexDefinition>
     */
    public function indexes(): array
    {
        return $this->indexes;
    }

    /**
     * @return list<ForeignKeyDefinition>
     */
    public function foreignKeys(): array
    {
        return $this->foreignKeys;
    }

    /**
     * @return list<string>
     */
    public function droppedColumns(): array
    {
        return $this->dropColumns;
    }

    /**
     * @return list<string>
     */
    public function droppedIndexes(): array
    {
        return $this->dropIndexes;
    }

    /**
     * @return list<string>
     */
    public function droppedForeignKeys(): array
    {
        return $this->dropForeignKeys;
    }

    private function assertAlterMode(string $operation): void
    {
        if ($this->mode !== self::ALTER) {
            throw new SchemaException(sprintf(
                '%s() is only available when altering an existing table (Schema::table()).',
                $operation,
            ));
        }
    }

    /**
     * @param string|list<string> $columns
     *
     * @return list<string>
     */
    private static function normaliseColumns(string|array $columns): array
    {
        $list = is_string($columns) ? [$columns] : $columns;

        if ($list === []) {
            throw new SchemaException('An index needs at least one column.');
        }

        foreach ($list as $column) {
            // Identifier::segments() is typed `string`; a non-string element
            // fails loudly rather than reaching SQL text.
            Identifier::segments($column);
        }

        return $list;
    }
}
