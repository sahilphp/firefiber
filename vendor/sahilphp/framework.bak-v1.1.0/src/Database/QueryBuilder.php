<?php

declare(strict_types=1);

namespace Sahil\Database;

use InvalidArgumentException;

/**
 * Composable query builder.
 *
 * Everything user-controlled becomes a bound parameter (`?`); table and
 * column names must be plain dotted identifiers (validated lazily at
 * composition time by {@see Identifier} and quoted by the driver), or a
 * deliberately-authored `Expression` for non-portable fragments.
 *
 * Grammar summary:
 *
 *     $users = $db->table('users')->as('u');
 *
 *     $users->select('u.id', 'u.name')
 *         ->where('u.status', '=', 'active')       // = <> != < > <= >= like
 *         ->orWhere('u.vip', '=', true)
 *         ->whereIn('u.role', ['admin', 'dev'])
 *         ->whereNull('u.deleted_at')
 *         ->join('profiles', 'profiles.user_id', 'u.id')   // inner|left|right
 *         ->orderBy('u.created_at', 'desc')
 *         ->limit(10)->offset(5)
 *         ->get();                                  // QueryResult
 *
 *     $users->where('u.id', '=', $id)->update(['u.name' => 'x']);
 *     $users->insert(['name' => 'x', 'email' => 'y']);
 *     $users->where('u.id', '=', $id)->delete();
 *
 * Safety invariants (all enforced with loud exceptions at execute time):
 *  - user values NEVER appear in SQL text — `toSql()` shows `?` only;
 *  - identifiers are validated/quoted per driver; unknown table/alias
 *    prefixes are refused (a prefix becomes known via table(), as() or
 *    join() — chains are order-independent because guards run lazily);
 *  - UPDATE / DELETE without an explicit WHERE refuse to run.
 */
final class QueryBuilder
{
    private const COMPARISON_OPERATORS = [
        '=', '<>', '!=', '<', '>', '<=', '>=', 'like',
    ];

    /**
     * @var list<array{connector: string, sql: string, params: list<mixed>, column: ?string}>
     */
    private array $conditions = [];

    /** @var list<string|Expression> */
    private array $columns = [];

    /** @var list<array{type: string, table: string, left: string, right: string}> */
    private array $joins = [];

    /** @var list<array{column: string, direction: string}> */
    private array $orders = [];

    private int $limitValue = 0;

    private int $offsetValue = 0;

    private bool $hasLimit = false;

    private bool $hasOffset = false;

    private readonly string $identifier; // validated table name

    /**
     * @var list<string>
     */
    private array $aliases = [];

    /**
     * @var list<string> base table names of registered joins
     */
    private array $joinedTables = [];

    public function __construct(
        private readonly Connection $connection,
        string $table,
    ) {
        Identifier::segments($table);
        $this->identifier = $table;
    }

    /**
     * Register a table alias; column prefixes must match the table or a
     * registered alias/join table.
     */
    public function as(string $alias): self
    {
        if (str_contains($alias, '.')) {
            throw new InvalidArgumentException('Table alias must be a single segment.');
        }

        Identifier::segments($alias);

        $clone = clone $this;
        $clone->aliases[] = $alias;

        return $clone;
    }

    private function prefixKnown(string $prefix): bool
    {
        if ($prefix === $this->identifier || str_contains($this->identifier, $prefix.'.')) {
            return true;
        }

        if (in_array($prefix, $this->aliases, true)) {
            return true;
        }

        return in_array($prefix, $this->joinedTables, true);
    }

    /**
     * Bare column names are fine; prefixed column names must reference
     * the table, an alias, or a joined table.
     */
    private function guardColumn(string $column): string
    {
        Identifier::segments($column);

        if (str_contains($column, '.')) {
            $prefix = explode('.', $column)[0];

            if (!$this->prefixKnown($prefix)) {
                throw new InvalidArgumentException(sprintf(
                    'Column [%s] references unknown table/alias [%s]; register it with table()/as()/join().',
                    $column,
                    $prefix,
                ));
            }
        }

        return $column;
    }

    /**
     * Selected columns — plain identifiers or `Expression`s.
     */
    public function select(string|Expression ...$columns): self
    {
        $clone = clone $this;
        $clone->columns = $columns;

        return $clone;
    }

    /**
     * AND condition; `$operator` ∈ = <> != < > <= >= like; `$value` is
     * bound, or bare null becomes IS NULL — nothing is ever inline.
     */
    public function where(string|Expression $column, string $operator = '=', mixed $value = null): self
    {
        if ($column instanceof Expression) {
            throw new InvalidArgumentException(
                'Use whereRaw() for SQL expressions; where() binds values only.',
            );
        }

        return $this->addCondition('AND', $column, $operator, $value);
    }

    /**
     * OR condition joined after the previous one.
     */
    public function orWhere(string $column, string $operator = '=', mixed $value = null): self
    {
        return $this->addCondition('OR', $column, $operator, $value);
    }

    private function addCondition(string $connector, string $column, string $operator, mixed $value): self
    {
        if ($value instanceof Expression) {
            throw new InvalidArgumentException(
                'Use whereRaw() for SQL expressions; where() binds values only.',
            );
        }

        if (!in_array($operator, self::COMPARISON_OPERATORS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported comparison operator [%s]; supported: %s (see also whereIn/whereNull).',
                $operator,
                implode(', ', self::COMPARISON_OPERATORS),
            ));
        }

        $sql = $value === null
            ? $this->connection->driver()->quoteIdentifier($column).' IS NULL'
            : $this->connection->driver()->quoteIdentifier($column).' '.strtoupper($operator).' ?';

        $clone = clone $this;
        $clone->conditions[] = [
            'connector' => $connector,
            'sql' => $sql,
            'params' => $value === null ? [] : [$value],
            'column' => $column,
        ];

        return $clone;
    }

    /**
     * `IN (...)` / `NOT IN (...)`; every element is bound separately.
     *
     * @param array<mixed> $values must be a plain list
     */
    public function whereIn(string $column, array $values, bool $negated = false, bool $or = false): self
    {
        if ($values === []) {
            throw new InvalidArgumentException('whereIn() needs at least one value (refusing degenerate SQL).');
        }

        if (!array_is_list($values)) {
            throw new InvalidArgumentException('whereIn() values must be a plain list (no string keys).');
        }

        foreach ($values as $value) {
            if (is_array($value)) {
                throw new InvalidArgumentException('whereIn() refuses nested arrays; flatten them.');
            }
        }

        $placeholders = implode(', ', array_fill(0, count($values), '?'));

        $clone = clone $this;
        $clone->conditions[] = [
            'connector' => $or ? 'OR' : 'AND',
            'sql' => sprintf(
                '%s %sIN (%s)',
                $this->connection->driver()->quoteIdentifier($column),
                $negated ? 'NOT ' : '',
                $placeholders,
            ),
            'params' => $values,
            'column' => $column,
        ];

        return $clone;
    }

    /**
     * `IS NULL` / `IS NOT NULL`.
     */
    public function whereNull(string $column, bool $negated = false, bool $or = false): self
    {
        $clone = clone $this;
        $clone->conditions[] = [
            'connector' => $or ? 'OR' : 'AND',
            'sql' => sprintf(
                '%s IS %sNULL',
                $this->connection->driver()->quoteIdentifier($column),
                $negated ? 'NOT ' : '',
            ),
            'params' => [],
            'column' => $column,
        ];

        return $clone;
    }

    /**
     * Explicitly-authored raw condition fragment (Expression) — the ONLY
     * way to express non-portable WHERE logic; values still bind.
     *
     * @param array<mixed> $bindings must be a plain list
     */
    public function whereRaw(Expression $expression, array $bindings = []): self
    {
        if (!array_is_list($bindings)) {
            throw new InvalidArgumentException('whereRaw() bindings must be a plain list (no string keys).');
        }

        foreach ($bindings as $index => $binding) {
            if (is_array($binding)) {
                throw new InvalidArgumentException(
                    sprintf('Array binding at index [%s] is not supported.', $index),
                );
            }
        }

        $clone = clone $this;
        $clone->conditions[] = [
            'connector' => 'AND',
            'sql' => '('.$expression->sql.')',
            'params' => $bindings,
            'column' => null,
        ];

        return $clone;
    }

    /**
     * JOIN with an ON pair. Structural only — no ON values.
     */
    public function join(string $table, string $left, string $right, string $type = 'inner'): self
    {
        $type = strtolower($type);

        if (!in_array($type, ['inner', 'left', 'right'], true)) {
            throw new InvalidArgumentException(sprintf('Join type [%s] must be inner, left or right.', $type));
        }

        Identifier::segments($table);

        $clone = clone $this;
        $clone->joinedTables[] = explode('.', $table)[0];
        $clone->joins[] = [
            'type' => $type,
            'table' => $table,
            'left' => $left,
            'right' => $right,
        ];

        return $clone;
    }

    /**
     * ORDER BY — direction restricted to ASC|DESC.
     */
    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $direction = strtoupper($direction);

        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            throw new InvalidArgumentException(
                sprintf('Order direction must be ASC or DESC, got [%s].', $direction),
            );
        }

        $clone = clone $this;
        $clone->orders[] = ['column' => $column, 'direction' => $direction];

        return $clone;
    }

    public function limit(int $limit): self
    {
        if ($limit < 0) {
            throw new InvalidArgumentException('LIMIT must be non-negative.');
        }

        $clone = clone $this;
        $clone->hasLimit = true;
        $clone->limitValue = $limit;

        return $clone;
    }

    public function offset(int $offset): self
    {
        if ($offset < 0) {
            throw new InvalidArgumentException('OFFSET must be non-negative.');
        }

        $clone = clone $this;
        $clone->hasOffset = true;
        $clone->offsetValue = $offset;

        return $clone;
    }

    /**
     * The composed SELECT statement and its bound parameters (tests
     * inspect these; user values NEVER appear in the SQL text).
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public function toSql(): array
    {
        $this->guardAll();

        $driver = $this->connection->driver();
        $columns = $this->columns === []
            ? '*'
            : implode(', ', array_map(
                static fn (string|Expression $column): string => $column instanceof Expression
                    ? '('.$column->sql.')'
                    : $driver->quoteIdentifier($column),
                $this->columns,
            ));

        $sql = 'SELECT '.$columns.' FROM '.$driver->quoteIdentifier($this->identifier);

        if ($this->aliases !== []) {
            $sql .= ' AS '.$driver->quoteIdentifier($this->aliases[count($this->aliases) - 1]);
        }

        foreach ($this->joins as $join) {
            $sql .= sprintf(
                ' %s JOIN %s ON %s = %s',
                strtoupper($join['type']),
                $driver->quoteIdentifier($join['table']),
                $driver->quoteIdentifier($join['left']),
                $driver->quoteIdentifier($join['right']),
            );
        }

        [$where, $whereParams] = $this->composeWhere();

        if ($where !== '') {
            $sql .= ' WHERE '.$where;
        }

        if ($this->orders !== []) {
            $sql .= ' ORDER BY '.implode(', ', array_map(
                static fn (array $order): string => $driver->quoteIdentifier($order['column'])
                    .' '.$order['direction'],
                $this->orders,
            ));
        }

        if ($this->hasLimit) {
            $sql .= ' LIMIT '.$this->limitValue;
        }

        if ($this->hasOffset) {
            $sql .= ' OFFSET '.$this->offsetValue;
        }

        return [$sql, $whereParams];
    }

    /**
     * Validate every stored identifier once, lazily — by now aliases and
     * joins have been registered.
     */
    private function guardAll(): void
    {
        foreach ($this->columns as $column) {
            if (!$column instanceof Expression) {
                $this->guardColumn($column);
            }
        }

        foreach ($this->joins as $join) {
            $this->guardColumn($join['left']);
            $this->guardColumn($join['right']);
        }

        foreach ($this->conditions as $condition) {
            if ($condition['column'] !== null) {
                $this->guardColumn($condition['column']);
            }
        }

        foreach ($this->orders as $order) {
            $this->guardColumn($order['column']);
        }
    }

    /**
     * Compose WHERE text + bound parameters.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function composeWhere(): array
    {
        $parts = [];
        $params = [];

        foreach ($this->conditions as $condition) {
            if ($parts === []) {
                $parts[0] = $condition['sql'];
                $params = $condition['params'];

                continue;
            }

            $parts[] = $condition['connector'].' '.$condition['sql'];
            $params = [...$params, ...$condition['params']];
        }

        return [implode(' ', $parts), $params];
    }

    /**
     * COUNT(*) over the same WHERE/JOIN set (for pagination).
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public function countSql(): array
    {
        $this->guardAll();
        $driver = $this->connection->driver();

        $sql = 'SELECT COUNT(*) AS '.$driver->quoteIdentifier('aggregate')
            .' FROM '.$driver->quoteIdentifier($this->identifier);

        if ($this->aliases !== []) {
            $sql .= ' AS '.$driver->quoteIdentifier($this->aliases[count($this->aliases) - 1]);
        }

        foreach ($this->joins as $join) {
            $sql .= sprintf(
                ' %s JOIN %s ON %s = %s',
                strtoupper($join['type']),
                $driver->quoteIdentifier($join['table']),
                $driver->quoteIdentifier($join['left']),
                $driver->quoteIdentifier($join['right']),
            );
        }

        [$where, $whereParams] = $this->composeWhere();

        if ($where !== '') {
            $sql .= ' WHERE '.$where;
        }

        return [$sql, $whereParams];
    }

    /**
     * Execute the composed SELECT.
     */
    public function get(): QueryResult
    {
        [$sql, $params] = $this->toSql();

        return $this->connection->execute($sql, $params);
    }

    /**
     * First row of the composed SELECT or null.
     *
     * @return array<string, mixed>|null
     */
    public function first(): ?array
    {
        return $this->limit(1)->get()->first();
    }

    /**
     * Parameterized UPDATE. WHERE required; column keys must be known
     * identifiers; values become bound, `Expression` values inline.
     *
     * @param array<mixed> $values string keys; non-string keys rejected
     */
    public function update(array $values): QueryResult
    {
        $this->guardAll();

        if ($this->conditions === []) {
            throw new InvalidArgumentException('Refusing UPDATE without WHERE — add an explicit condition.');
        }

        if ($values === []) {
            throw new InvalidArgumentException('UPDATE needs at least one column = value.');
        }

        $driver = $this->connection->driver();
        $assignments = [];
        $params = [];

        foreach ($values as $column => $value) {
            if (!is_string($column)) {
                throw new InvalidArgumentException('UPDATE column keys must be strings.');
            }

            $this->guardColumn($column);
            $quoted = $driver->quoteIdentifier($column);

            if ($value instanceof Expression) {
                $assignments[] = $quoted.' = '.$value->sql;

                continue;
            }

            if (is_array($value)) {
                throw new InvalidArgumentException(
                    sprintf('UPDATE value for [%s] must not be an array; flatten it.', $column),
                );
            }

            $assignments[] = $quoted.' = ?';
            $params[] = $value;
        }

        [$where, $whereParams] = $this->composeWhere();

        return $this->connection->execute(
            'UPDATE '.$driver->quoteIdentifier($this->identifier)
                .' SET '.implode(', ', $assignments)
                .' WHERE '.$where,
            [...$params, ...$whereParams],
        );
    }

    /**
     * Parameterized INSERT (single row); values become bound
     * parameters; `Expression` values inline.
     *
     * @param array<mixed> $values string keys; non-string keys rejected
     */
    public function insert(array $values): QueryResult
    {
        if ($values === []) {
            throw new InvalidArgumentException('INSERT needs at least one column = value.');
        }

        $driver = $this->connection->driver();
        $columns = [];
        $placeholders = [];
        $params = [];

        foreach ($values as $column => $value) {
            if (!is_string($column)) {
                throw new InvalidArgumentException('INSERT column keys must be strings.');
            }

            $columns[] = $driver->quoteIdentifier($column);

            if ($value instanceof Expression) {
                $placeholders[] = $value->sql;

                continue;
            }

            if (is_array($value)) {
                throw new InvalidArgumentException(
                    sprintf('INSERT value for [%s] must not be an array.', $column),
                );
            }

            $placeholders[] = '?';
            $params[] = $value;
        }

        return $this->connection->execute(
            'INSERT INTO '.$driver->quoteIdentifier($this->identifier)
                .' ('.implode(', ', $columns).') VALUES ('.implode(', ', $placeholders).')',
            $params,
        );
    }

    /**
     * Parameterized DELETE. WHERE is MANDATORY in this builder.
     */
    public function delete(): QueryResult
    {
        $this->guardAll();

        if ($this->conditions === []) {
            throw new InvalidArgumentException('Refusing DELETE without WHERE — add an explicit condition.');
        }

        [$where, $whereParams] = $this->composeWhere();

        return $this->connection->execute(
            'DELETE FROM '.$this->connection->driver()->quoteIdentifier($this->identifier)
                .' WHERE '.$where,
            $whereParams,
        );
    }
}
