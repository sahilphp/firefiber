<?php

declare(strict_types=1);

namespace Sahil\Database;

use PDO;
use Sahil\Database\Drivers\MySqlDriver;
use Sahil\Database\Drivers\PostgresDriver;
use Sahil\Database\Drivers\SqliteDriver;

/**
 * Builds validated PDO connections from configuration arrays.
 *
 * Accepted configuration (documented in README):
 *
 *     [
 *         'driver'   => 'sqlite',      // 'sqlite' | 'mysql' | 'pgsql'
 *         'database' => 'database/database.sqlite', // sqlite file, or :memory:
 *         // mysql/pgsql also use host, port, database, username, password
 *         'charset'  => 'utf8mb4',     // mysql only
 *     ]
 */
final class ConnectionFactory
{
    /** @var array{mysql: class-string<MySqlDriver>, pgsql: class-string<PostgresDriver>, sqlite: class-string<SqliteDriver>} */
    private const DRIVERS = [
        'mysql' => MySqlDriver::class,
        'pgsql' => PostgresDriver::class,
        'sqlite' => SqliteDriver::class,
    ];

    /**
     * @param array<string, mixed> $configuration
     */
    public function make(array $configuration): Connection
    {
        $driver = $configuration['driver'] ?? null;

        if (!is_string($driver) || !isset(self::DRIVERS[$driver])) {
            throw new ConnectionException(sprintf(
                'Unsupported database driver [%s]; supported: %s.',
                is_scalar($driver) ? (string) $driver : '(value not scalar)',
                implode(', ', array_keys(self::DRIVERS)),
            ));
        }

        /** @var class-string<DriverContract> $driverClass */
        $driverClass = self::DRIVERS[$driver];
        $driverInstance = new $driverClass();

        $extension = $driverInstance->extension();

        if (!extension_loaded($extension)) {
            throw new ConnectionException(sprintf(
                'The [%s] extension is required by the [%s] driver but is not installed.',
                $extension,
                $driver,
            ));
        }

        if ($driver === 'sqlite') {
            $this->ensureSqliteFile($configuration);
        }

        $dsn = $driverInstance->dsn($configuration);

        /** @var ?string $username */
        $username = is_string($configuration['username'] ?? null) ? $configuration['username'] : null;
        /** @var ?string $password */
        $password = is_string($configuration['password'] ?? null) ? $configuration['password'] : null;

        $dialectOptions = $driverInstance->pdoOptions($configuration);

        $factory = static fn (array $arguments): PDO => new PDO(
            $arguments['dsn'],
            $arguments['username'],
            $arguments['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION] + $arguments['options'],
        );

        return Connection::connect($driverInstance, $dsn, $username, $password, $dialectOptions, $factory);
    }

    /**
     * SQLite creates the database file on first connect but not its
     * directory. Creating both here means a fresh project can run
     * `php sahil migrate` with no database configuration at all.
     *
     * @param array<string, mixed> $configuration
     */
    private function ensureSqliteFile(array $configuration): void
    {
        $database = $configuration['database'] ?? null;

        if (!is_string($database) || trim($database) === '' || $database === ':memory:' || is_file($database)) {
            return;
        }

        $directory = dirname($database);

        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new ConnectionException(sprintf('Unable to create the SQLite directory [%s].', $directory));
        }

        if (@touch($database) === false) {
            throw new ConnectionException(sprintf('Unable to create the SQLite database file [%s].', $database));
        }
    }
}
