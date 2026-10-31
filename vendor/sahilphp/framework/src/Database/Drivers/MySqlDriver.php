<?php

declare(strict_types=1);

namespace Sahil\Database\Drivers;

use InvalidArgumentException;
use Sahil\Database\QuotingDriver;
use Sahil\Database\Schema\MySqlGrammar;
use Sahil\Database\Schema\SchemaGrammar;

/**
 * MySQL/MariaDB dialect (uses the `pdo_mysql` extension). Identifiers
 * are quoted with backticks; user values are always bound, never inline.
 */
final class MySqlDriver extends QuotingDriver
{
    public function extension(): string
    {
        return 'pdo_mysql';
    }

    /**
     * @param array<string, mixed> $configuration
     */
    public function dsn(array $configuration): string
    {
        $endpoint = $this->validatedEndpoint($configuration);
        $database = $configuration['database'] ?? null;

        if (!is_string($database) || $database === '') {
            throw new InvalidArgumentException('MySQL configuration requires a database name.');
        }

        $charset = $configuration['charset'] ?? 'utf8mb4';

        return sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $endpoint['host'],
            $endpoint['port'],
            $database,
            preg_match('/^[A-Za-z0-9_+\-.]+$/', $charset) === 1 ? $charset : 'utf8mb4',
        );
    }

    /**
     * @param array<string, mixed> $configuration
     *
     * @return array<int, mixed>
     */
    public function pdoOptions(array $configuration): array
    {
        return isset($configuration['strict']) && $configuration['strict'] !== false
            ? [1002 => "SET sql_mode='STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'"]
            : [];
    }

    /** @return list<string> */
    protected function quoteMarks(): array
    {
        return ['`', '`'];
    }

    public function supportsSavepoints(): bool
    {
        return true;
    }

    public function grammar(): SchemaGrammar
    {
        return new MySqlGrammar($this);
    }

    /**
     * MySQL/MariaDB DDL statements cause an implicit COMMIT, so a broken
     * migration cannot be rolled back at the schema level.
     */
    public function supportsTransactionalDdl(): bool
    {
        return false;
    }

    /**
     * `GET_LOCK` is SERVER-global in MySQL, so callers must include the
     * database name in `$name` when several databases share a server.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public function lockSql(string $name, int $timeoutSeconds): array
    {
        return ['SELECT GET_LOCK(?, ?) AS acquired', [$name, max(0, $timeoutSeconds)]];
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    public function unlockSql(string $name): array
    {
        return ['SELECT RELEASE_LOCK(?) AS released', [$name]];
    }
}
