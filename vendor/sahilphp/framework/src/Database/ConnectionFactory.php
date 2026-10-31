<?php

declare(strict_types=1);

namespace Sahil\Database;

use PDO;
use Sahil\Database\Drivers\MySqlDriver;
use Sahil\Database\Drivers\PostgresDriver;

/**
 * Builds validated PDO connections from configuration arrays.
 *
 * Accepted configuration (documented in README):
 *
 *     [
 *         'driver'   => 'mysql',       // 'mysql' | 'pgsql'
 *         'host'     => '127.0.0.1',
 *         'port'     => 3306,          // int
 *         'database' => 'sahil',
 *         'username' => 'app',
 *         'password' => 'secret',      // never logged, never in messages
 *         'charset'  => 'utf8mb4',     // mysql only
 *     ]
 */
final class ConnectionFactory
{
    /** @var array{mysql: class-string<MySqlDriver>, pgsql: class-string<PostgresDriver>} */
    private const DRIVERS = [
        'mysql' => MySqlDriver::class,
        'pgsql' => PostgresDriver::class,
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
}
