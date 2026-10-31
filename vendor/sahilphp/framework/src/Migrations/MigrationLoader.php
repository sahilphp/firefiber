<?php

declare(strict_types=1);

namespace Sahil\Migrations;

use Throwable;

/**
 * Discovers migration files.
 *
 * Rules:
 *  - only `*.php` files directly inside the migrations directory are read
 *    (sub-directories, dotfiles and editor backups are ignored);
 *  - execution order is the file name order — use the conventional
 *    `YYYY_MM_DD_HHMMSS_name.php` prefix so it is also chronological;
 *  - every file MUST `return` a {@see Migration} instance; anything else is
 *    a load failure naming the file, never a silent skip.
 *
 * A missing directory is normal (a project with no migrations yet) and
 * yields an empty list; an unreadable directory is an error.
 */
final class MigrationLoader
{
    public function __construct(private readonly string $directory)
    {
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * @return list<DiscoveredMigration> sorted by name (execution order)
     */
    public function load(): array
    {
        if (!is_dir($this->directory)) {
            return [];
        }

        $files = glob(rtrim($this->directory, '/').'/*.php');

        if ($files === false) {
            throw new MigrationException(sprintf(
                'Unable to scan the migrations directory [%s].',
                $this->directory,
            ));
        }

        sort($files, SORT_STRING);

        $migrations = [];

        foreach ($files as $file) {
            $migrations[] = $this->loadFile($file);
        }

        return $migrations;
    }

    /**
     * Load one migration by name (file name without extension).
     */
    public function loadNamed(string $name): DiscoveredMigration
    {
        $path = rtrim($this->directory, '/').'/'.$name.'.php';

        if (!is_file($path)) {
            throw MigrationException::notFound($name);
        }

        return $this->loadFile($path);
    }

    private function loadFile(string $path): DiscoveredMigration
    {
        try {
            /** @var mixed $migration */
            $migration = require $path;
        } catch (Throwable $failure) {
            throw MigrationException::unloadable($path, $failure->getMessage());
        }

        if (!$migration instanceof Migration) {
            throw MigrationException::unloadable(
                $path,
                sprintf(
                    'the file must return a %s instance, got %s',
                    Migration::class,
                    get_debug_type($migration),
                ),
            );
        }

        return new DiscoveredMigration(basename($path, '.php'), $path, $migration);
    }
}
