<?php

declare(strict_types=1);

namespace Sahil\Orm;

use JsonSerializable;

/**
 * One page of a paginated query, JSON-ready in the documented shape:
 * `{"data": [...], "meta": {"current_page", "per_page", "total", "last_page"}}`.
 */
final class PageResult implements JsonSerializable
{
    public function __construct(
        public readonly OrmResult $result,
        public readonly int $currentPage,
        public readonly int $perPage,
        public readonly int $total,
    ) {
    }

    public function lastPage(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function toArray(): array
    {
        return [
            'data' => $this->result->toArray(),
            'meta' => [
                'current_page' => $this->currentPage,
                'per_page' => $this->perPage,
                'total' => $this->total,
                'last_page' => $this->lastPage(),
            ],
        ];
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
