<?php

declare(strict_types=1);

namespace Sahil\Database\Schema;

use Sahil\Database\Identifier;

/**
 * One index (plain, unique or primary) in a {@see Table} blueprint.
 *
 * `$name === null` means "let the grammar derive a deterministic name"
 * (`{table}_{column[,column]}_{kind}`); the derivation is driver-agnostic
 * and length-capped for MySQL's 64-character identifier limit.
 */
final class IndexDefinition
{
    public const KIND_INDEX = 'index';

    public const KIND_UNIQUE = 'unique';

    public const KIND_PRIMARY = 'primary';

    /** @param list<string> $columns */
    public function __construct(
        private readonly array $columns,
        private readonly string $kind = self::KIND_INDEX,
        private readonly ?string $name = null,
    ) {
        if ($columns === []) {
            throw new SchemaException('An index needs at least one column.');
        }

        foreach ($columns as $column) {
            // Identifier::segments() is typed `string`, so a non-string
            // element fails loudly here rather than reaching SQL.
            Identifier::segments($column);
        }

        if (!in_array($kind, [self::KIND_INDEX, self::KIND_UNIQUE, self::KIND_PRIMARY], true)) {
            throw new SchemaException(sprintf('Unsupported index kind [%s].', $kind));
        }

        if ($name !== null) {
            Identifier::segments($name);
        }
    }

    /**
     * @return list<string>
     */
    public function columns(): array
    {
        return $this->columns;
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function name(): ?string
    {
        return $this->name;
    }
}
