<?php

declare(strict_types=1);

namespace Sahil\Database\Schema;

use Sahil\Database\Identifier;

/**
 * One foreign key in a {@see Table} blueprint.
 *
 * Referential actions are allowlisted: only the standard SQL actions are
 * accepted, so an action can never smuggle arbitrary SQL into the
 * generated constraint. An incomplete key (no referenced table or column)
 * is refused when the grammar compiles it, never silently emitted.
 */
final class ForeignKeyDefinition
{
    /** Standard SQL referential actions, accepted with spaces or underscores. */
    private const ACTIONS = [
        'cascade',
        'restrict',
        'set null',
        'no action',
        'set default',
    ];

    private ?string $referencesColumn = null;

    private ?string $onTable = null;

    private ?string $onDelete = null;

    private ?string $onUpdate = null;

    private ?string $name = null;

    public function __construct(private readonly string $column)
    {
        Identifier::segments($column);
    }

    public function references(string $column): self
    {
        Identifier::segments($column);
        $this->referencesColumn = $column;

        return $this;
    }

    public function on(string $table): self
    {
        Identifier::segments($table);
        $this->onTable = $table;

        return $this;
    }

    public function onDelete(string $action): self
    {
        $this->onDelete = self::normaliseAction($action);

        return $this;
    }

    public function onUpdate(string $action): self
    {
        $this->onUpdate = self::normaliseAction($action);

        return $this;
    }

    /**
     * Explicit constraint name; defaults to `{table}_{column}_foreign`.
     */
    public function name(string $name): self
    {
        Identifier::segments($name);
        $this->name = $name;

        return $this;
    }

    public function column(): string
    {
        return $this->column;
    }

    public function referencedTable(): ?string
    {
        return $this->onTable;
    }

    public function referencedColumn(): ?string
    {
        return $this->referencesColumn;
    }

    public function deleteAction(): ?string
    {
        return $this->onDelete;
    }

    public function updateAction(): ?string
    {
        return $this->onUpdate;
    }

    public function explicitName(): ?string
    {
        return $this->name;
    }

    /**
     * @throws SchemaException when `references()`/`on()` were never called
     */
    public function assertComplete(): void
    {
        if ($this->referencesColumn === null || $this->onTable === null) {
            throw new SchemaException(sprintf(
                'Foreign key on column [%s] is incomplete; call references(<column>) and on(<table>).',
                $this->column,
            ));
        }
    }

    private static function normaliseAction(string $action): string
    {
        $normalised = strtolower(trim(str_replace('_', ' ', $action)));
        $normalised = (string) preg_replace('/\s+/', ' ', $normalised);

        if (!in_array($normalised, self::ACTIONS, true)) {
            throw new SchemaException(sprintf(
                'Unsupported referential action [%s]; supported: %s.',
                $action,
                implode(', ', self::ACTIONS),
            ));
        }

        return strtoupper($normalised);
    }
}
