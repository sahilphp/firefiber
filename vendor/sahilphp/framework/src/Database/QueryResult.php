<?php

declare(strict_types=1);

namespace Sahil\Database;

use PDO;
use PDOException;
use PDOStatement;

/**
 * Predictable view over one executed statement.
 *
 * Data comes out as `list<array<string, mixed>>` — column names as
 * written (unaliased or aliased by you), values as PDO typed them.
 */
final class QueryResult
{
    /**
     * @var list<array<string, mixed>>|null
     */
    private ?array $fetched = null;

    public function __construct(
        private readonly PDOStatement $statement,
        private readonly PDO $pdo,
    ) {
    }

    /**
     * All matched rows, fetched once and memoized.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(): array
    {
        if ($this->fetched !== null) {
            return $this->fetched;
        }

        try {
            /** @var list<array<string, mixed>> $rows */
            $rows = $this->statement->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $exception) {
            throw new QueryException($this->statement->queryString, [], $exception->getMessage());
        }

        $this->fetched = $rows;

        return $rows;
    }

    /**
     * First row or null.
     *
     * @return array<string, mixed>|null
     */
    public function first(): ?array
    {
        $rows = $this->rows();

        return $rows[0] ?? null;
    }

    /**
     * Rows affected by INSERT / UPDATE / DELETE; for SELECT statements
     * use {@see rows()} and count — rowCount() is not portable.
     */
    public function affected(): int
    {
        try {
            return max(0, $this->statement->rowCount());
        } catch (PDOException $exception) {
            throw new QueryException($this->statement->queryString, [], $exception->getMessage());
        }
    }

    /**
     * Driver-dependent last insert id (string per PDO contract), null
     * when the driver has none (PostgreSQL: prefer `RETURNING id`).
     */
    public function lastInsertId(): ?string
    {
        $id = $this->pdo->lastInsertId();

        return $id === '' ? null : $id;
    }
}
