<?php

declare(strict_types=1);

namespace Sahil\Database;

/**
 * An explicitly-authored raw SQL fragment. Slipping user-controlled text
 * through this class is a developer error BY DESIGN — raw expressions
 * are loud at the call site (you must construct `new Expression(...)`),
 * and everywhere else the query builder validates identifiers or binds
 * parameters.
 */
final class Expression
{
    public function __construct(public readonly string $sql)
    {
        if (trim($this->sql) === '') {
            throw new \InvalidArgumentException('Raw SQL expression must not be empty.');
        }
    }
}
