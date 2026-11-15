<?php

declare(strict_types=1);

namespace Sahil\Migrations;

use ReflectionMethod;
use Sahil\Database\Schema\Schema;

/**
 * One migration.
 *
 * A migration file lives in `database/migrations/` and RETURNS an instance,
 * conventionally an anonymous class:
 *
 * ```php
 * // database/migrations/2026_01_01_000000_create_users_table.php
 * return new class extends Migration {
 *     public function up(Schema $schema): void
 *     {
 *         $schema->create('users', function (Table $table): void {
 *             $table->id();
 *             $table->string('email', 190)->unique();
 *             $table->timestamps();
 *         });
 *     }
 *
 *     public function down(Schema $schema): void
 *     {
 *         $schema->dropIfExists('users');
 *     }
 * };
 * ```
 *
 * Reversibility is DERIVED, not declared: a migration is reversible exactly
 * when it overrides `down()`. Forward-only migrations therefore cannot be
 * rolled back by mistake — the migrator refuses them before running
 * anything. Override {@see isReversible()} to force a value when a
 * migration is deliberately one-way even though it defines `down()`.
 */
abstract class Migration
{
    /**
     * Apply the migration.
     */
    abstract public function up(Schema $schema): void;

    /**
     * Reverse the migration. Not implementing it makes the migration
     * forward-only; the default implementation throws if it is ever called.
     */
    public function down(Schema $schema): void
    {
        throw new IrreversibleMigrationException(static::class);
    }

    /**
     * Whether `down()` was actually implemented. Callers MUST check this
     * before invoking `down()`.
     */
    public function isReversible(): bool
    {
        return (new ReflectionMethod($this, 'down'))->getDeclaringClass()->getName() !== self::class;
    }
}
