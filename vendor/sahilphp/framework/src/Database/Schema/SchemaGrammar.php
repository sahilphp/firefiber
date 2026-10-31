<?php

declare(strict_types=1);

namespace Sahil\Database\Schema;

/**
 * Compiles a {@see Table} blueprint into DDL for one dialect.
 *
 * Every method returns a LIST of statements: dialects differ in what can
 * be expressed in a single statement (PostgreSQL cannot declare a plain
 * index inside `CREATE TABLE`, so those become separate `CREATE INDEX`
 * statements). Statements are executed in order by {@see Schema}.
 */
interface SchemaGrammar
{
    /** Dialect label matching the connection's driver, e.g. `mysql`. */
    public function dialect(): string;

    /**
     * Statements for a fresh table (plus any statements the dialect
     * cannot express inline).
     *
     * @return list<string>
     */
    public function compileCreate(Table $table): array;

    /**
     * Statements that apply changes to an existing table.
     *
     * @return list<string>
     */
    public function compileAlter(Table $table): array;

    public function compileDrop(string $table, bool $ifExists): string;

    /**
     * Existence probe for one table; the table name travels as a bound
     * parameter, never concatenated into the SQL text.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public function compileTableExists(string $table): array;
}
