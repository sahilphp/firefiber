<?php

declare(strict_types=1);

namespace Sahil\Migrations;

use Sahil\Database\Connection;
use Sahil\Database\Schema\Schema;
use Throwable;

/**
 * Runs registered seeders.
 *
 * Rules:
 *  - a seeder runs at most once per successful execution, however many
 *    times it is reached through `SeedContext::call()` — the cycle guard
 *    makes a self-referencing or mutually-referencing set terminate;
 *  - a FAILED seeder is not marked as executed, so a retry is possible;
 *  - each seeder runs inside a transaction, so a failure leaves no
 *    half-inserted rows;
 *  - a failure names the seeder and keeps the driver's message; it is never
 *    swallowed.
 */
final class SeederRunner
{
    /** @var array<string, true> seeders that completed in this execution */
    private array $executed = [];

    /** @var array<string, true> seeders currently on the call stack */
    private array $running = [];

    /** @var list<string> seeders started, in start order (includes composite calls) */
    private array $order = [];

    private readonly Schema $schema;

    public function __construct(
        private readonly Connection $connection,
        private readonly SeederLoader $loader,
    ) {
        $this->schema = new Schema($connection);
    }

    /**
     * Run every registered seeder in name order.
     *
     * @throws MigrationException when a seeder fails
     */
    public function runAll(): MigrationReport
    {
        foreach ($this->loader->names() as $name) {
            $this->runOne($name);
        }

        // Report everything that actually executed, in order — including
        // seeders reached through a composite SeedContext::call(), which the
        // name loop itself would never have listed.
        $applied = $this->order;

        return new MigrationReport(
            command: 'db:seed',
            applied: $applied,
            notes: $applied === []
                ? ['no seeder needed to run']
                : [sprintf('%d seeder(s) executed', count($applied))],
        );
    }

    /**
     * Run one seeder by name; returns null when it already ran (or is
     * currently running, i.e. a cycle).
     *
     * @throws MigrationException when the seeder is unknown or fails
     */
    public function runOne(string $name): ?string
    {
        if (isset($this->executed[$name]) || isset($this->running[$name])) {
            return null;
        }

        $seeder = $this->loader->get($name);

        $this->running[$name] = true;
        $this->order[] = $name;

        try {
            $this->connection->transaction(function () use ($seeder): void {
                $seeder->run($this->context());
            });
        } catch (Throwable $failure) {
            throw new MigrationException(
                sprintf('Seeder [%s] failed: %s (transaction rolled back)', $name, $failure->getMessage()),
                0,
                $failure,
            );
        } finally {
            unset($this->running[$name]);
        }

        $this->executed[$name] = true;

        return $name;
    }

    private function context(): SeedContext
    {
        return new SeedContext(
            $this->connection,
            $this->schema,
            function (string $seeder): void {
                $this->runOne($seeder);
            },
        );
    }
}
