<?php

declare(strict_types=1);

namespace Sahil\Migrations;

use Throwable;

/**
 * Discovers and registers seeders.
 *
 * Discovery mirrors {@see MigrationLoader}: `*.php` files directly inside
 * the seeders directory, each RETURNING a {@see Seeder} instance, registered
 * under its file name. A missing directory is normal (no seeders yet); a
 * file that returns something else is a loud failure naming the file.
 *
 * Loading is lazy per name, so `db:seed --class=UsersSeeder` does not
 * execute or even instantiate anything it does not need.
 */
final class SeederLoader
{
    public function __construct(private readonly string $directory)
    {
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * Registered seeder names, sorted (the default execution order).
     *
     * @return list<string>
     */
    public function names(): array
    {
        if (!is_dir($this->directory)) {
            return [];
        }

        $files = glob(rtrim($this->directory, '/').'/*.php');

        if ($files === false) {
            throw new MigrationException(sprintf(
                'Unable to scan the seeders directory [%s].',
                $this->directory,
            ));
        }

        $names = array_map(
            static fn (string $file): string => basename($file, '.php'),
            $files,
        );

        sort($names, SORT_STRING);

        return $names;
    }

    public function has(string $name): bool
    {
        return is_file($this->path($name));
    }

    /**
     * Registered seeder instance for one name.
     *
     * @throws MigrationException when the name is not registered or the file is invalid
     */
    public function get(string $name): Seeder
    {
        $path = $this->path($name);

        if (!is_file($path)) {
            throw new MigrationException(sprintf(
                'No seeder named [%s] is registered in [%s].',
                $name,
                $this->directory,
            ));
        }

        try {
            /** @var mixed $seeder */
            $seeder = require $path;
        } catch (Throwable $failure) {
            throw new MigrationException(
                sprintf('Seeder file [%s] failed to load: %s', $path, $failure->getMessage()),
                0,
                $failure,
            );
        }

        if (!$seeder instanceof Seeder) {
            throw new MigrationException(sprintf(
                'Seeder file [%s] must return a %s instance, got %s.',
                $path,
                Seeder::class,
                get_debug_type($seeder),
            ));
        }

        return $seeder;
    }

    private function path(string $name): string
    {
        return rtrim($this->directory, '/').'/'.$name.'.php';
    }
}
