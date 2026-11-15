<?php

declare(strict_types=1);

namespace Sahil\Migrations;

/**
 * One seeder.
 *
 * A seeder file lives in `database/seeders/` and RETURNS an instance, keyed
 * by its file name:
 *
 * ```php
 * // database/seeders/UsersSeeder.php
 * return new class extends Seeder {
 *     public function run(SeedContext $context): void
 *     {
 *         // Every value goes through the parameterized query builder.
 *         $context->connection->table('users')->insert([
 *             'name' => 'Ada',
 *             'email' => 'ada@example.test',
 *         ]);
 *     }
 * };
 * ```
 *
 * Seeders must be idempotent or explicitly destructive: `db:seed` is treated
 * as a destructive operation and requires operator intent (see the README).
 */
abstract class Seeder
{
    abstract public function run(SeedContext $context): void;
}
