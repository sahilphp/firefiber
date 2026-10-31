<?php

declare(strict_types=1);

namespace Sahil\Orm;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

/**
 * Ordered collection of models with a small, predictable surface.
 *
 * @implements IteratorAggregate<int, Model>
 */
final class OrmResult implements IteratorAggregate, Countable, JsonSerializable
{
    /**
     * @param list<Model> $items
     */
    public function __construct(private readonly array $items)
    {
    }

    /**
     * @return list<Model>
     */
    public function all(): array
    {
        return $this->items;
    }

    public function first(): ?Model
    {
        return $this->items[0] ?? null;
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    /**
     * Nested serialization (each item applies its own hidden rules).
     *
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(
            static fn (Model $model): array => $model->toArray(),
            $this->items,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
