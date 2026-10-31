<?php

declare(strict_types=1);

namespace Sahil\Migrations;

use Closure;
use Sahil\Database\Connection;
use Sahil\Database\Schema\Schema;

/**
 * What a seeder gets to work with: the connection (parameterized query
 * builder), the schema builder, and a way to invoke another registered
 * seeder — which is how a `DatabaseSeeder` composes the rest.
 *
 * Re-running a seeder that already ran in this execution is a no-op, so a
 * cyclic `call()` cannot loop forever.
 */
final class SeedContext
{
    /**
     * @param Closure(string): void $dispatcher runs another seeder by name
     */
    public function __construct(
        public readonly Connection $connection,
        public readonly Schema $schema,
        private readonly Closure $dispatcher,
    ) {
    }

    /**
     * Run another registered seeder (by file name without extension).
     */
    public function call(string $seeder): void
    {
        ($this->dispatcher)($seeder);
    }
}
