<?php

declare(strict_types=1);

namespace Sahil\Database\Schema;

use Sahil\Database\Expression;
use Sahil\Database\Identifier;

/**
 * One column in a {@see Table} blueprint.
 *
 * Deliberately a plain description (name + type + modifiers); every
 * driver-specific rendering decision belongs to the grammar, so the same
 * blueprint produces backtick MySQL DDL and double-quoted PostgreSQL DDL.
 */
final class ColumnDefinition
{
    /** Column types understood by every shipped grammar. */
    public const TYPES = [
        'id',
        'foreignId',
        'bigInteger',
        'integer',
        'string',
        'text',
        'boolean',
        'timestamp',
        'dateTime',
        'decimal',
        'float',
        'json',
    ];

    private bool $nullable = false;

    private bool $autoIncrement = false;

    private bool $unsigned = false;

    private bool $primary = false;

    private bool $unique = false;

    private bool $hasDefault = false;

    private mixed $default = null;

    private ?int $length = null;

    private ?int $precision = null;

    private ?int $scale = null;

    public function __construct(
        private readonly string $name,
        private readonly string $type,
    ) {
        if (!in_array($type, self::TYPES, true)) {
            throw new SchemaException(sprintf(
                'Unsupported column type [%s]; supported: %s.',
                $type,
                implode(', ', self::TYPES),
            ));
        }

        Identifier::segments($name);
    }

    public function nullable(bool $nullable = true): self
    {
        $this->nullable = $nullable;

        return $this;
    }

    /**
     * Literal default. Scalars only — the grammar quotes and escapes the
     * value for its dialect (DDL cannot take bound parameters). Use
     * {@see defaultExpression()} for `CURRENT_TIMESTAMP`-style values.
     */
    public function default(mixed $value): self
    {
        $isExpression = $value instanceof Expression;

        if (!$isExpression && !is_scalar($value) && $value !== null) {
            throw new SchemaException(sprintf(
                'Default value for column [%s] must be scalar, null or an Expression, got %s.',
                $this->name,
                get_debug_type($value),
            ));
        }

        $this->hasDefault = true;
        $this->default = $value;

        return $this;
    }

    /**
     * Raw-SQL default (e.g. `CURRENT_TIMESTAMP`). Explicit opt-in: the text
     * is author-controlled migration code, never request data.
     */
    public function defaultExpression(string $sql): self
    {
        return $this->default(new Expression($sql));
    }

    public function autoIncrement(): self
    {
        $this->autoIncrement = true;

        return $this;
    }

    public function unsigned(): self
    {
        $this->unsigned = true;

        return $this;
    }

    public function primary(bool $primary = true): self
    {
        $this->primary = $primary;

        return $this;
    }

    public function unique(bool $unique = true): self
    {
        $this->unique = $unique;

        return $this;
    }

    public function length(int $length): self
    {
        if ($length < 1) {
            throw new SchemaException(sprintf('Length for column [%s] must be >= 1.', $this->name));
        }

        $this->length = $length;

        return $this;
    }

    public function precision(int $precision, int $scale = 2): self
    {
        if ($precision < 1 || $scale < 0 || $scale > $precision) {
            throw new SchemaException(sprintf(
                'Precision/scale for column [%s] are invalid (%d, %d).',
                $this->name,
                $precision,
                $scale,
            ));
        }

        $this->precision = $precision;
        $this->scale = $scale;

        return $this;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function isNullable(): bool
    {
        return $this->nullable;
    }

    public function isAutoIncrement(): bool
    {
        return $this->autoIncrement;
    }

    public function isUnsigned(): bool
    {
        return $this->unsigned;
    }

    public function isPrimary(): bool
    {
        return $this->primary;
    }

    public function isUnique(): bool
    {
        return $this->unique;
    }

    public function hasDefault(): bool
    {
        return $this->hasDefault;
    }

    public function defaultValue(): mixed
    {
        return $this->default;
    }

    public function lengthValue(): ?int
    {
        return $this->length;
    }

    public function precisionValue(): ?int
    {
        return $this->precision;
    }

    public function scaleValue(): ?int
    {
        return $this->scale;
    }
}
