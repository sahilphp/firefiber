<?php

declare(strict_types=1);

namespace Sahil\Migrations;

use Sahil\Database\Connection;
use Sahil\Database\Identifier;
use Sahil\Database\Schema\Schema;
use Sahil\Database\Schema\Table;

/**
 * Reads and writes the applied-migration ledger.
 *
 * The table is created on demand (`createIfNotExists`, so a concurrent
 * creator cannot collide) using the framework's own schema builder — the
 * migration system dogfoods the abstraction it ships. Every read and write
 * goes through parameterized statements; the only text interpolated into
 * SQL is validated, driver-quoted identifiers.
 *
 * Read paths never create the table: `migrate:status` on a fresh database
 * reports everything as pending without mutating the schema.
 */
final class MigrationRepository
{
    public const DEFAULT_TABLE = 'sahil_migrations';

    private readonly Schema $schema;

    public function __construct(
        private readonly Connection $connection,
        private readonly string $table = self::DEFAULT_TABLE,
    ) {
        Identifier::segments($table);

        $this->schema = new Schema($connection);
    }

    public function table(): string
    {
        return $this->table;
    }

    public function exists(): bool
    {
        return $this->schema->hasTable($this->table);
    }

    /**
     * Create the ledger table if it is missing (fresh installations).
     */
    public function ensure(): void
    {
        $this->schema->createIfNotExists($this->table, static function (Table $table): void {
            $table->id();
            $table->string('migration', 191)->unique();
            $table->integer('batch');
            $table->timestamp('applied_at')->nullable();
        });
    }

    /**
     * Applied migrations, oldest batch first.
     *
     * @return list<array{name: string, batch: int, applied_at: string|null}>
     */
    public function applied(): array
    {
        if (!$this->exists()) {
            return [];
        }

        $rows = $this->connection->execute(sprintf(
            'SELECT %s AS name, %s AS batch, %s AS applied_at FROM %s ORDER BY %s ASC, %s ASC',
            $this->quote('migration'),
            $this->quote('batch'),
            $this->quote('applied_at'),
            $this->quote($this->table),
            $this->quote('batch'),
            $this->quote('migration'),
        ))->rows();

        $applied = [];

        foreach ($rows as $row) {
            $applied[] = [
                'name' => (string) $row['name'],
                'batch' => (int) $row['batch'],
                'applied_at' => $row['applied_at'] === null ? null : (string) $row['applied_at'],
            ];
        }

        return $applied;
    }

    /**
     * Applied migration names mapped to the batch they ran in.
     *
     * @return array<string, int>
     */
    public function batches(): array
    {
        $batches = [];

        foreach ($this->applied() as $row) {
            $batches[$row['name']] = $row['batch'];
        }

        return $batches;
    }

    /**
     * The next batch number (1 on a fresh ledger).
     */
    public function nextBatch(): int
    {
        $max = 0;

        foreach ($this->batches() as $batch) {
            $max = max($max, $batch);
        }

        return $max + 1;
    }

    /**
     * Batch numbers, newest first.
     *
     * @return list<int>
     */
    public function batchNumbersNewestFirst(): array
    {
        $numbers = array_values(array_unique(array_values($this->batches())));

        rsort($numbers, SORT_NUMERIC);

        return $numbers;
    }

    /**
     * Names applied in the given batches, NEWEST first — the order a
     * rollback must use (the reverse of application order).
     *
     * @param list<int> $batches
     *
     * @return list<string>
     */
    public function namesForBatches(array $batches): array
    {
        if ($batches === []) {
            return [];
        }

        $names = [];

        foreach ($this->applied() as $row) {
            if (in_array($row['batch'], $batches, true)) {
                $names[] = $row['name'];
            }
        }

        return array_reverse($names);
    }

    public function record(string $name, int $batch): void
    {
        $this->connection->execute(
            sprintf(
                'INSERT INTO %s (%s, %s, %s) VALUES (?, ?, ?)',
                $this->quote($this->table),
                $this->quote('migration'),
                $this->quote('batch'),
                $this->quote('applied_at'),
            ),
            [$name, $batch, date('Y-m-d H:i:s')],
        );
    }

    public function forget(string $name): void
    {
        $this->connection->execute(
            sprintf(
                'DELETE FROM %s WHERE %s = ?',
                $this->quote($this->table),
                $this->quote('migration'),
            ),
            [$name],
        );
    }

    private function quote(string $identifier): string
    {
        return $this->connection->driver()->quoteIdentifier($identifier);
    }
}
