<?php

declare(strict_types=1);

namespace Sahil\Firefiber\Data;

use Sahil\Firefiber\Exceptions\InvalidStateException;

/**
 * Server-driven pagination, filtering, searching and sorting helper.
 *
 * Accepts plain arrays or objects and never performs database access or
 * arbitrary SQL. Only fields enabled in the provided configuration can be
 * sorted or filtered, which makes the class safe to feed directly from
 * browser-controlled component state.
 */
final class DataTable
{
    /**
     * @param list<array<string, mixed>|object> $items
     * @param array<string, mixed> $options
     */
    public function __construct(
        private readonly array $items,
        private readonly array $options = [],
    ) {
    }

    /**
     * @param array<string, mixed> $state
     */
    public function paginate(array $state): PaginatedResult
    {
        $page = $this->integer($state['page'] ?? 1, 1);
        $perPage = $this->integer($state['perPage'] ?? $this->options['perPage'] ?? 25, 1, 1, 1000);
        $search = $this->string($state['search'] ?? '');
        $sort = $this->allowedSort($this->string($state['sort'] ?? ''));
        $direction = strtolower((string) ($state['direction'] ?? $this->options['defaultSort']['direction'] ?? 'asc'));
        $direction = $direction === 'desc' ? 'desc' : 'asc';
        $filters = $state['filters'] ?? $this->options['defaultFilters'] ?? [];

        if (!is_array($filters)) {
            $filters = [];
        }

        $filtered = $this->filterItems($this->items, $filters);
        $filtered = $this->searchItems($filtered, $search);
        $filtered = $this->sortItems($filtered, $sort, $direction);

        $total = count($filtered);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $lastPage));
        $offset = ($page - 1) * $perPage;
        $data = array_values(array_slice($filtered, $offset, $perPage));

        return new PaginatedResult(
            data: $data,
            total: $total,
            perPage: $perPage,
            currentPage: $page,
            lastPage: $lastPage,
            from: $total === 0 ? 0 : $offset + 1,
            to: $total === 0 ? 0 : min($total, $offset + $perPage),
            sort: $sort,
            direction: $direction,
            search: $search,
            filters: $filters,
        );
    }

    /**
     * @param list<array<string, mixed>|object> $items
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>|object>
     */
    private function filterItems(array $items, array $filters): array
    {
        $definitions = $this->options['filters'] ?? [];

        if (!is_array($definitions)) {
            $definitions = [];
        }

        foreach ($filters as $field => $value) {
            if (!is_string($field) || $field === '' || !array_key_exists($field, $definitions)) {
                continue;
            }

            $definition = $definitions[$field];
            $operator = is_array($definition) && is_string($definition['operator'] ?? null)
                ? strtolower($definition['operator'])
                : 'eq';

            $items = array_values(array_filter(
                $items,
                function (mixed $item) use ($field, $value, $operator): bool {
                    $itemValue = $this->value($item, $field);

                    return match ($operator) {
                        'like' => is_string($value) && str_contains(mb_strtolower((string) $itemValue), mb_strtolower($value)),
                        'in' => is_array($value) && in_array($itemValue, $value, false),
                        'gt', '>' => is_numeric($itemValue) && is_numeric($value) && $itemValue > $value,
                        'gte', '>=' => is_numeric($itemValue) && is_numeric($value) && $itemValue >= $value,
                        'lt', '<' => is_numeric($itemValue) && is_numeric($value) && $itemValue < $value,
                        'lte', '<=' => is_numeric($itemValue) && is_numeric($value) && $itemValue <= $value,
                        default => $itemValue === $value || (is_string($value) && $itemValue === $value),
                    };
                },
            ));
        }

        return $items;
    }

    /**
     * @param list<array<string, mixed>|object> $items
     * @return list<array<string, mixed>|object>
     */
    private function searchItems(array $items, string $search): array
    {
        $search = mb_strtolower(trim($search));

        if ($search === '') {
            return $items;
        }

        $fields = $this->options['searchableFields'] ?? [];

        if (!is_array($fields) || $fields === []) {
            throw new InvalidStateException('Search requires at least one searchable field.');
        }

        return array_values(array_filter(
            $items,
            function (mixed $item) use ($fields, $search): bool {
                foreach ($fields as $field) {
                    if (!is_string($field)) {
                        continue;
                    }

                    if (str_contains(mb_strtolower((string) $this->value($item, $field)), $search)) {
                        return true;
                    }
                }

                return false;
            },
        ));
    }

    /**
     * @param list<array<string, mixed>|object> $items
     * @return list<array<string, mixed>|object>
     */
    private function sortItems(array $items, string $sort, string $direction): array
    {
        if ($sort === '') {
            return $items;
        }

        $multiplier = $direction === 'desc' ? -1 : 1;

        usort(
            $items,
            function (mixed $a, mixed $b) use ($sort, $multiplier): int {
                $aValue = $this->value($a, $sort);
                $bValue = $this->value($b, $sort);

                return $multiplier * $this->compare($aValue, $bValue);
            },
        );

        return $items;
    }

    private function allowedSort(string $sort): string
    {
        if ($sort === '') {
            return (string) ($this->options['defaultSort']['field'] ?? '');
        }

        $fields = $this->options['sortableFields'] ?? [];

        if (!is_array($fields) || !in_array($sort, $fields, true)) {
            return (string) ($this->options['defaultSort']['field'] ?? '');
        }

        return $sort;
    }

    private function compare(mixed $a, mixed $b): int
    {
        if ($a === null && $b === null) {
            return 0;
        }

        if ($a === null) {
            return -1;
        }

        if ($b === null) {
            return 1;
        }

        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a <=> (float) $b;
        }

        if (is_bool($a) && is_bool($b)) {
            return ((int) $a) <=> ((int) $b);
        }

        return strcmp(mb_strtolower((string) $a), mb_strtolower((string) $b));
    }

    private function value(mixed $item, string $field): mixed
    {
        if (is_array($item)) {
            return $item[$field] ?? null;
        }

        if (is_object($item)) {
            if (isset($item->$field)) {
                return $item->$field;
            }

            $method = 'get'.str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $field)));

            return method_exists($item, $method) ? $item->$method() : null;
        }

        // Scalar rows compare on their own value so simple lists work.
        return $field === '' || $field === 'value' ? $item : null;
    }

    private function integer(mixed $value, int $default, int $min = 0, ?int $max = null): int
    {
        $number = is_numeric($value) ? (int) $value : $default;
        $number = max($min, $number);

        return $max === null ? $number : min($max, $number);
    }

    private function string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
