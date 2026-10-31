<?php

declare(strict_types=1);

namespace Sahil\Database;

use RuntimeException;

/**
 * Thrown while executing a query. The message contains the SQL and the
 * driver error, never the bound parameter values (they may hold user
 * data); `parameters()` exposes them for trusted logs.
 */
final class QueryException extends RuntimeException
{
    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        private readonly string $sql,
        private readonly array $parameters,
        private readonly string $driverError,
    ) {
        parent::__construct(
            sprintf('Query failed (%s): %s', $driverError, $sql),
        );
    }

    public function sql(): string
    {
        return $this->sql;
    }

    /**
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        return $this->parameters;
    }

    public function driverError(): string
    {
        return $this->driverError;
    }
}
