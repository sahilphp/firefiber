<?php

declare(strict_types=1);

namespace Sahil\Migrations;

use Closure;
use Sahil\Database\Connection;
use Sahil\Database\Schema\Schema;
use Throwable;

/**
 * Discovers, applies and reverses migrations.
 *
 * Guarantees:
 *
 *  - **Repeatable**: already-applied migrations are never re-run; a second
 *    `migrate` on an unchanged database reports nothing pending and writes
 *    nothing.
 *  - **Fresh installations**: the ledger table is created on first
 *    `migrate()`; `status()` is read-only and creates nothing.
 *  - **Transactional where the driver allows it**: on PostgreSQL each
 *    migration runs in a transaction, so a failure rolls its DDL back
 *    completely. On MySQL/MariaDB DDL causes an implicit commit, so the
 *    failure is reported with an explicit warning that partial DDL may
 *    remain — the framework never pretends a rollback happened.
 *  - **Accurate ledger**: a migration is recorded only AFTER `up()`
 *    succeeded, so a failed migration stays pending and is retried.
 *  - **Single writer**: the whole run holds an advisory lock
 *    ({@see MigrationLock}), so two processes cannot migrate at once.
 *  - **Deliberate destruction**: rollback and production runs go through
 *    {@see MutationGuard}.
 */
final class Migrator
{
    private readonly Schema $schema;

    public function __construct(
        private readonly Connection $connection,
        private readonly MigrationRepository $repository,
        private readonly MigrationLoader $loader,
        private readonly MutationGuard $guard,
        private readonly MigrationLock $lock,
    ) {
        $this->schema = new Schema($connection);
    }

    /**
     * Read-only status: every discovered migration with its applied state.
     *
     * @return array{applied: list<array{name: string, batch: int, applied_at: string|null}>, pending: list<string>, missing: list<string>}
     */
    public function status(): array
    {
        $applied = $this->repository->applied();
        $batches = $this->repository->batches();
        $discovered = array_map(
            static fn (DiscoveredMigration $migration): string => $migration->name,
            $this->loader->load(),
        );

        $pending = array_values(array_filter(
            $discovered,
            static fn (string $name): bool => !array_key_exists($name, $batches),
        ));

        // Recorded but no longer present on disk: needs operator attention.
        $missing = array_values(array_filter(
            array_keys($batches),
            static fn (string $name): bool => !in_array($name, $discovered, true),
        ));

        return [
            'applied' => $applied,
            'pending' => $pending,
            'missing' => $missing,
        ];
    }

    /**
     * Apply every pending migration in file-name order.
     *
     * A production run is authorised through {@see MutationGuard} (the
     * documented force flag only), and the whole run holds {@see MigrationLock}.
     *
     * @throws MigrationException when the lock is held, or a migration fails
     */
    public function migrate(bool $force = false): MigrationReport
    {
        return $this->lock->synchronized(function () use ($force): MigrationReport {
            $this->repository->ensure();

            $applied = $this->repository->batches();
            $pending = array_values(array_filter(
                $this->loader->load(),
                static fn (DiscoveredMigration $migration): bool => !array_key_exists($migration->name, $applied),
            ));

            if ($pending === []) {
                return new MigrationReport(
                    command: 'migrate',
                    notes: ['nothing to migrate — every discovered migration is already applied'],
                );
            }

            // A schema change in production is destructive by nature: it
            // needs the documented force flag, not just a typed "yes".
            if ($this->guard->isProduction()) {
                $this->guard->authorise(
                    'migrate',
                    $force,
                    sprintf('Apply %d migration(s) to %s?', count($pending), $this->guard->environment()),
                );
            }

            $batch = $this->repository->nextBatch();
            $names = [];

            foreach ($pending as $migration) {
                $this->runUp($migration);
                $this->repository->record($migration->name, $batch);
                $names[] = $migration->name;
            }

            return new MigrationReport(
                command: 'migrate',
                applied: $names,
                notes: [sprintf('batch %d — %d migration(s) applied', $batch, count($names))],
            );
        });
    }

    /**
     * Reverse the newest `$steps` batches, newest migration first.
     *
     * Authorisation and the forward-only gate are enforced by
     * {@see MutationGuard} before any statement runs.
     *
     * @throws MigrationException when the lock is held or a file is missing
     */
    public function rollback(bool $force = false, int $steps = 1): MigrationReport
    {
        if ($steps < 1) {
            throw new MigrationException('rollback steps must be >= 1.');
        }

        return $this->lock->synchronized(function () use ($force, $steps): MigrationReport {
            if (!$this->repository->exists()) {
                return new MigrationReport(
                    command: 'migrate:rollback',
                    notes: ['nothing to roll back — no migrations have been applied'],
                );
            }

            $batches = array_slice($this->repository->batchNumbersNewestFirst(), 0, $steps);
            $names = $this->repository->namesForBatches($batches);

            if ($names === []) {
                return new MigrationReport(
                    command: 'migrate:rollback',
                    notes: ['nothing to roll back — the ledger is empty'],
                );
            }

            $discovered = [];

            foreach ($this->loader->load() as $migration) {
                $discovered[$migration->name] = $migration;
            }

            $irreversible = [];
            $orphaned = [];

            foreach ($names as $name) {
                if (!isset($discovered[$name])) {
                    $orphaned[] = $name;

                    continue;
                }

                if (!$discovered[$name]->migration->isReversible()) {
                    $irreversible[] = $name;
                }
            }

            // Orphaned records block a rollback unless the operator
            // deliberately forces the ledger to forget them.
            if ($orphaned !== [] && !$force) {
                throw new MigrationException(sprintf(
                    'Refusing [migrate:rollback]: %d applied migration(s) have no file on disk (%s). '
                    .'Restore them, or pass --force to forget the ledger records deliberately.',
                    count($orphaned),
                    implode(', ', $orphaned),
                ));
            }

            $this->guard->authoriseIrreversible('migrate:rollback', $irreversible, $force);
            $this->guard->authorise(
                'migrate:rollback',
                $force,
                sprintf(
                    'Roll back %d migration(s) from batch(es) %s?',
                    count($names),
                    implode(', ', $batches),
                ),
            );

            $rolledBack = [];
            $skipped = [];

            foreach ($names as $name) {
                if (!isset($discovered[$name])) {
                    $this->repository->forget($name);
                    $skipped[] = $name.' (ledger record forgotten; file missing)';

                    continue;
                }

                if (!$discovered[$name]->migration->isReversible()) {
                    $skipped[] = $name.' (forward-only; left applied)';

                    continue;
                }

                $this->runDown($discovered[$name]);
                $this->repository->forget($name);
                $rolledBack[] = $name;
            }

            return new MigrationReport(
                command: 'migrate:rollback',
                rolledBack: $rolledBack,
                skipped: $skipped,
                notes: $rolledBack === []
                    ? ['no migration was reversed']
                    : [sprintf('%d migration(s) rolled back', count($rolledBack))],
            );
        });
    }

    private function runUp(DiscoveredMigration $migration): void
    {
        $this->execute($migration, function () use ($migration): void {
            $migration->migration->up($this->schema);
        }, 'up');
    }

    private function runDown(DiscoveredMigration $migration): void
    {
        $this->execute($migration, function () use ($migration): void {
            $migration->migration->down($this->schema);
        }, 'down');
    }

    /**
     * Run one direction of a migration, wrapping it in a transaction only
     * when the driver supports transactional DDL.
     */
    private function execute(DiscoveredMigration $migration, Closure $operation, string $direction): void
    {
        $transactional = $this->connection->driver()->supportsTransactionalDdl();

        try {
            if ($transactional) {
                $this->connection->transaction(static function () use ($operation): void {
                    $operation();
                });
            } else {
                $operation();
            }
        } catch (Throwable $failure) {
            throw MigrationException::forMigration(
                $migration->name,
                sprintf(
                    '%s() reported: %s%s',
                    $direction,
                    $failure->getMessage(),
                    $transactional
                        ? ' (transaction rolled back; no schema change was kept)'
                        : ' (WARNING: this driver cannot roll DDL back — the schema may be partially changed; inspect before retrying)',
                ),
                $failure,
            );
        }
    }

}
