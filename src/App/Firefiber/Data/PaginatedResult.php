<?php

declare(strict_types=1);

namespace Sahil\Firefiber\Data;

use ArrayAccess;
use JsonSerializable;

final class PaginatedResult implements ArrayAccess, JsonSerializable
{
    /**
     * @param list<array<string, mixed>|object> $data
     * @param array<string, mixed> $filters
     */
    public function __construct(
        public readonly array $data,
        public readonly int $total,
        public readonly int $perPage,
        public readonly int $currentPage,
        public readonly int $lastPage,
        public readonly int $from,
        public readonly int $to,
        public readonly string $sort = '',
        public readonly string $direction = 'asc',
        public readonly string $search = '',
        public readonly array $filters = [],
    ) {
    }

    public function hasPages(): bool
    {
        return $this->lastPage > 1;
    }

    public function hasPrevious(): bool
    {
        return $this->currentPage > 1;
    }

    public function hasNext(): bool
    {
        return $this->currentPage < $this->lastPage;
    }

    public function previousPage(): int
    {
        return max(1, $this->currentPage - 1);
    }

    public function nextPage(): int
    {
        return min($this->lastPage, $this->currentPage + 1);
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->data[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->data[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('Paginated results are immutable.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('Paginated results are immutable.');
    }

    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
