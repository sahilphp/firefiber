<?php

declare(strict_types=1);

namespace Sahil\Database\Schema;

use RuntimeException;

/**
 * Thrown for schema-definition misuse: unsupported column types, unsafe
 * default values, unknown referential actions, incomplete foreign keys.
 *
 * Always loud: a migration that would emit ambiguous or unsafe DDL never
 * reaches the database.
 */
final class SchemaException extends RuntimeException
{
}
