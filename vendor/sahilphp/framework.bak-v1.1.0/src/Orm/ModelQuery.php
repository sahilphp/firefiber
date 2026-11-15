<?php

declare(strict_types=1);

namespace Sahil\Orm;

use InvalidArgumentException;
use Sahil\Database\Connection;
use Sahil\Database\QueryBuilder;
use Sahil\Database\QueryResult;

/**
 * Fluent query facade over one model type. Composed through the
 * underlying Phase-7 builder; every user value is still bound.
 *
 *     User::query()
 *         ->where('active', '=', 1)
 *         ->orderBy('id', 'desc')
 *         ->with('profile', 'posts.author')
 *         ->limit(10)
 *         ->get();              // OrmResult<User>
 *
 *     User::query()->paginate(15);   // PageResult
 *     Post::query()->published();    // scope via Post::scopePublished()
 *
 * Known limits (documented in README): no aggregate helpers beyond
 * pagination counts, no cursor reads, no soft deletes.
 */
final class ModelQuery
{
    private QueryBuilder $builder;

    /**
     * @var list<string> relation paths (`name` or dotted `name.child`)
     */
    private array $eagerRelationPaths = [];

    public function __construct(private readonly string $modelClass)
    {
        $this->builder = $this->modelForBuilder();
    }

    private function modelForBuilder(): QueryBuilder
    {
        $connection = $this->modelClass::shareConnection();

        if ($connection === null) {
            throw new InvalidArgumentException(
                "Model [$this->modelClass] has no connection; call its useConnection() once.",
            );
        }

        return $connection->table((new $this->modelClass())->table());
    }

    /* ---------- passthrough constraints ---------- */

    public function where(string $column, string $operator = '=', mixed $value = null): self
    {
        $this->builder = $this->builder->where($column, $operator, $value);

        return $this;
    }

    public function orWhere(string $column, string $operator = '=', mixed $value = null): self
    {
        $this->builder = $this->builder->orWhere($column, $operator, $value);

        return $this;
    }

    /**
     * @param array<mixed> $values
     */
    public function whereIn(string $column, array $values): self
    {
        $this->builder = $this->builder->whereIn($column, $values);

        return $this;
    }

    public function whereNull(string $column): self
    {
        $this->builder = $this->builder->whereNull($column);

        return $this;
    }

    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $this->builder = $this->builder->orderBy($column, $direction);

        return $this;
    }

    public function limit(int $limit): self
    {
        $this->builder = $this->builder->limit($limit);

        return $this;
    }

    public function offset(int $offset): self
    {
        $this->builder = $this->builder->offset($offset);

        return $this;
    }

    /**
     * Eager relations: `with('profile', 'posts.author', ...)` — each
     * dotted level becomes exactly one extra query (no N+1 fan-out).
     */
    public function with(string ...$relationPaths): self
    {
        foreach ($relationPaths as $path) {
            if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*)*$/', $path) !== 1) {
                throw new InvalidArgumentException(
                    sprintf('Relation path [%s] must be `name` or dotted `name.child`.', $path),
                );
            }
        }

        $this->eagerRelationPaths = [...$this->eagerRelationPaths, ...$relationPaths];

        return $this;
    }

    /**
     * Named scopes: `$posts->published()` → `Post::scopePublished($this)`.
     *
     * @param list<mixed> $args
     */
    public function __call(string $method, array $args): self
    {
        $scopeMethod = 'scope'.ucfirst($method);

        if (!method_exists($this->modelClass, $scopeMethod)) {
            throw new InvalidArgumentException(
                sprintf('No scope [%s] on [%s] (expected a %s() method).', $method, $this->modelClass, $scopeMethod),
            );
        }

        $prototype = new $this->modelClass();
        $prototype->{$scopeMethod}($this, ...$args);

        return $this;
    }

    /* ---------- hydration & reads ---------- */

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<Model>
     */
    /**
     * Raw DB rows arrive as column-name => value pairs (PDO FETCH_ASSOC
     * always yields string keys).
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<Model>
     */
    private function hydrate(array $rows): array
    {
        $models = [];

        foreach ($rows as $row) {
            /** @var Model $model */
            $model = new $this->modelClass();
            // DB rows are trusted ORM data: hydrate raw, snapshot as
            // original — NOT through fill() / mass assignment.
            foreach ($row as $key => $value) {
                $model->setRawAttribute($key, $value, false);
            }
            $models[] = $model;
        }

        return $models;
    }

    public function get(): OrmResult
    {
        $rows = $this->builder->get()->rows();
        /** @var list<Model> $models */
        $models = $this->hydrate($rows);

        (new EagerLoader())->attach($this->modelClass, $models, $this->eagerRelationPaths);

        return new OrmResult($models);
    }

    public function first(): ?Model
    {
        return $this->limit(1)->get()->first();
    }

    /**
     * Primary-key lookup.
     */
    public function find(mixed $id): ?Model
    {
        $key = (new $this->modelClass())->getPrimaryKey();
        $this->builder = $this->builder->where($key, '=', $id);

        return $this->limit(1)->get()->first();
    }

    /**
     * INSERT row values (Model::create path); returns the result so the
     * caller can read lastInsertId().
     *
     * @param array<string, mixed> $values
     */
    public function insertRow(array $values): QueryResult
    {
        return $this->builder->insert($values);
    }

    /**
     * UPDATE by primary key with explicit column = value bindings.
     *
     * @param array<string, mixed> $values
     */
    public function updateRow(mixed $id, array $values): void
    {
        $key = (new $this->modelClass())->getPrimaryKey();
        $this->builder = $this->builder->where($key, '=', $id);
        $this->update($values);
    }

    /**
     * DELETE by primary key.
     */
    public function deleteRow(mixed $id): void
    {
        $key = (new $this->modelClass())->getPrimaryKey();
        $this->builder = $this->builder->where($key, '=', $id);
        $this->delete();
    }

    /**
     * Row-level UPDATE with this query's WHERE (used by Model::save —
     * not a mass-assignment surface).
     *
     * @param array<string, mixed> $values
     */
    public function update(array $values): void
    {
        $this->builder->update($values);
    }

    /**
     * Row-level DELETE with this query's WHERE.
     */
    public function delete(): void
    {
        $this->builder->delete();
    }

    /**
     * `paginate($perPage, $page)` — exactly two queries: one COUNT(*)
     * with the composed constraints, one windowed page fetch.
     */
    public function paginate(int $perPage, int $page = 1): PageResult
    {
        if ($perPage < 1 || $page < 1) {
            throw new InvalidArgumentException('perPage and page must be >= 1.');
        }

        [$countSql, $countParams] = $this->builder->countSql();
        $countRows = $this->connection()->execute($countSql, $countParams)->rows();
        $countFirst = $countRows[0] ?? ['aggregate' => 0];
        $aggregate = reset($countFirst);
        $total = is_numeric($aggregate) ? (int) $aggregate : 0;

        $pageModels = $this
            ->limit($perPage)
            ->offset(($page - 1) * $perPage)
            ->get();

        return new PageResult(
            $pageModels,
            currentPage: $page,
            perPage: $perPage,
            total: $total,
        );
    }

    private function connection(): Connection
    {
        $connection = $this->modelClass::shareConnection();

        if ($connection === null) {
            throw new InvalidArgumentException("Model [$this->modelClass] has no connection set.");
        }

        return $connection;
    }
}
