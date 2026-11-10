<?php

declare(strict_types=1);

namespace Sahil\Console\Generator;

/**
 * Shared code-generation service.
 *
 * One place owns root detection, PSR-4 namespace resolution, destination
 * paths, class-name validation, existing-file protection, stub rendering,
 * atomic writes and PHP syntax checks. Generators never touch the filesystem
 * themselves.
 */
final class Generator
{
    /** @var list<string> */
    private const RESERVED = [
        'abstract', 'and', 'array', 'as', 'break', 'callable', 'case', 'catch',
        'class', 'clone', 'const', 'continue', 'declare', 'default', 'die', 'do',
        'echo', 'else', 'elseif', 'empty', 'enddeclare', 'endfor', 'endforeach',
        'endif', 'endswitch', 'endwhile', 'enum', 'eval', 'exit', 'extends',
        'final', 'finally', 'fn', 'for', 'foreach', 'function', 'global', 'goto',
        'if', 'implements', 'include', 'include_once', 'instanceof', 'insteadof',
        'interface', 'isset', 'list', 'match', 'namespace', 'new', 'or', 'print',
        'private', 'protected', 'public', 'readonly', 'require', 'require_once',
        'return', 'static', 'switch', 'throw', 'trait', 'try', 'unset', 'use',
        'var', 'while', 'xor', 'yield', 'true', 'false', 'null', 'self', 'parent',
    ];

    /** @var array<string, string> generator type => sub-namespace */
    private const NAMESPACES = [
        'controller' => 'Http\\Controllers',
        'model' => 'Models',
        'middleware' => 'Http\\Middleware',
        'policy' => 'Policies',
        'provider' => 'Providers',
        'command' => 'Console\\Commands',
    ];

    private readonly ApplicationPaths $paths;

    public function __construct(string $basePath)
    {
        $this->paths = ApplicationPaths::for($basePath);
    }

    public function applicationNamespace(): string
    {
        return $this->paths->applicationNamespace();
    }

    /**
     * @param array{force?: bool, migration?: bool} $options
     */
    public function generate(string $type, string $name, array $options = []): GeneratorResult
    {
        $force = ($options['force'] ?? false) === true;

        return match ($type) {
            'controller', 'model', 'middleware', 'policy', 'provider', 'command' => $this->classFile(
                $type,
                $name,
                $force,
                $type === 'model' && ($options['migration'] ?? false) === true,
            ),
            'migration' => $this->migration($name, $force),
            'seeder' => $this->seeder($name, $force),
            default => throw new GeneratorException(sprintf('Unknown generator type [%s].', $type)),
        };
    }

    private function classFile(string $type, string $name, bool $force, bool $withMigration): GeneratorResult
    {
        [$class, $subNamespace] = $this->splitClassName($name);
        $applicationNamespace = $this->paths->applicationNamespace();
        $namespace = $applicationNamespace.'\\'.self::NAMESPACES[$type];

        if ($subNamespace !== []) {
            $namespace .= '\\'.implode('\\', $subNamespace);
        }

        [, $directory] = $this->paths->resolve($namespace);
        $target = rtrim($directory, '/').'/'.$class.'.php';

        $contents = match ($type) {
            'controller' => Stubs::controller($namespace, $class),
            'model' => Stubs::model($namespace, $class, $this->tableName($class)),
            'middleware' => Stubs::middleware($namespace, $class),
            'policy' => Stubs::policy($namespace, $class, $applicationNamespace.'\\Models\\'.$this->policyModelName($class)),
            'provider' => Stubs::provider($namespace, $class),
            'command' => Stubs::command($namespace, $class, $this->commandName($class)),
        };

        $migrationTarget = null;
        $migrationName = null;
        $table = null;

        if ($withMigration) {
            $table = $this->tableName($class);
            $migrationName = 'create_'.$table.'_table';
            $migrationTarget = $this->migrationTarget($migrationName);

            // Preflight the second file before writing the first, so a
            // conflict cannot leave a half-generated model behind.
            if (is_file($migrationTarget) && !$force) {
                throw new GeneratorException(sprintf(
                    '%s already exists; pass --force to overwrite it.',
                    $this->paths->relative($migrationTarget),
                ));
            }
        }

        $files = [$this->write($target, $contents, $force, $class, $namespace)];

        if ($withMigration) {
            $files[] = $this->writeMigration(
                $migrationName,
                [],
                $force,
                Stubs::migrationCreate($table),
                $migrationTarget,
            );
        }

        return new GeneratorResult($files);
    }

    private function migration(string $name, bool $force): GeneratorResult
    {
        $name = trim($name);

        if (preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
            throw new GeneratorException(sprintf(
                'Invalid migration name [%s]; use lower_snake_case, for example create_users_table.',
                $name,
            ));
        }

        if (preg_match('/^create_([a-z][a-z0-9_]*)_table$/', $name, $matches) === 1) {
            $table = $matches[1];
            $contents = Stubs::migrationCreate($table);
        } elseif (preg_match(
            '/^add_([a-z][a-z0-9_]*(?:_and_[a-z][a-z0-9_]*)*)_to_([a-z][a-z0-9_]*)_table$/',
            $name,
            $matches,
        ) === 1) {
            $table = $matches[2];
            $columns = explode('_and_', $matches[1]);

            foreach ($columns as $column) {
                $this->assertIdentifier($column, 'column');
            }

            $contents = Stubs::migrationAdd($table, $columns);
        } else {
            throw new GeneratorException(sprintf(
                'Unsupported migration name [%s]. Use create_<table>_table or add_<column>_to_<table>_table.',
                $name,
            ));
        }

        $this->assertIdentifier($table, 'table');

        return new GeneratorResult([$this->writeMigration($name, [], $force, $contents)]);
    }

    /**
     * Write a migration, refusing to declare the same migration twice unless
     * `--force` explicitly replaces the existing file.
     */
    private function writeMigration(
        string $name,
        array $columns,
        bool $force,
        ?string $contents = null,
        ?string $target = null,
    ): GeneratedFile {
        $target ??= $this->migrationTarget($name);

        if ($contents === null) {
            $contents = $columns === []
                ? Stubs::migrationCreate($name)
                : Stubs::migrationAdd($name, $columns);
        }

        return $this->write($target, $contents, $force, $name, null);
    }

    /**
     * Resolve the migration file name, reusing an existing migration with the
     * same name and otherwise choosing a collision-free chronological prefix.
     */
    private function migrationTarget(string $name): string
    {
        $directory = $this->paths->root().'/database/migrations';
        $existing = glob($directory.'/*_'.$name.'.php') ?: [];
        sort($existing);

        if ($existing !== []) {
            return $existing[0];
        }

        // Two migrations generated in the same second must still sort in
        // creation order. Advance the timestamp until its prefix is free.
        $timestamp = time();

        do {
            $prefix = gmdate('Y_m_d_His', $timestamp);
            $collision = glob($directory.'/'.$prefix.'_*.php') ?: [];
            $timestamp++;
        } while ($collision !== []);

        return $directory.'/'.$prefix.'_'.$name.'.php';
    }

    private function seeder(string $name, bool $force): GeneratorResult
    {
        [$class] = $this->splitClassName($name);
        $target = $this->paths->root().'/database/seeders/'.$class.'.php';

        return new GeneratorResult([
            $this->write($target, Stubs::seeder($class), $force, $class, null),
        ]);
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private function splitClassName(string $name): array
    {
        $original = trim($name);

        if ($original === '') {
            throw new GeneratorException('A class name is required.');
        }

        $normalized = str_replace('\\', '/', $original);

        if (str_starts_with($normalized, '/') || str_contains($normalized, '..') || str_contains($normalized, '//')) {
            throw new GeneratorException(sprintf('Invalid class name [%s]: path traversal is not allowed.', $original));
        }

        $normalized = trim($normalized, '/');
        $parts = explode('/', $normalized);

        foreach ($parts as $part) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $part) !== 1) {
                throw new GeneratorException(sprintf(
                    'Invalid class name [%s]: [%s] is not a valid PHP identifier segment.',
                    $original,
                    $part,
                ));
            }

            if (in_array(strtolower($part), self::RESERVED, true)) {
                throw new GeneratorException(sprintf(
                    'Invalid class name [%s]: [%s] is a reserved PHP word.',
                    $original,
                    $part,
                ));
            }
        }

        $class = array_pop($parts);

        return [$class, $parts];
    }

    private function assertIdentifier(string $value, string $label): void
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $value) !== 1) {
            throw new GeneratorException(sprintf('Invalid %s name [%s].', $label, $value));
        }

        if (in_array($value, self::RESERVED, true)) {
            throw new GeneratorException(sprintf('Invalid %s name [%s]: reserved word.', $label, $value));
        }
    }

    /**
     * `UserPolicy` documents the `User` model; a policy without the suffix
     * documents a model with the same name.
     */
    private function policyModelName(string $class): string
    {
        return str_ends_with($class, 'Policy') && strlen($class) > 6
            ? substr($class, 0, -6)
            : $class;
    }

    private function tableName(string $class): string
    {
        $snake = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $class));

        if (str_ends_with($snake, 'y') && preg_match('/[aeiou]y$/', $snake) !== 1) {
            return substr($snake, 0, -1).'ies';
        }

        if (preg_match('/(s|x|z|ch|sh)$/', $snake) === 1) {
            return $snake.'es';
        }

        return $snake.'s';
    }

    private function commandName(string $class): string
    {
        $base = str_ends_with($class, 'Command') ? substr($class, 0, -7) : $class;
        $kebab = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $base));

        return 'app:'.$kebab;
    }

    /**
     * Write one generated file atomically.
     *
     * Existing files are never replaced silently; `--force` backs the current
     * content up first, then replaces it. The new content is syntax-checked
     * before it becomes visible.
     */
    private function write(
        string $target,
        string $contents,
        bool $force,
        string $className,
        ?string $namespace,
    ): GeneratedFile {
        $this->paths->assertInside($target);
        $relative = $this->paths->relative($target);
        $backup = null;

        if (is_file($target)) {
            if (!$force) {
                throw new GeneratorException(sprintf(
                    '%s already exists; pass --force to overwrite it.',
                    $relative,
                ));
            }

            $backupDirectory = $this->paths->root().'/storage/framework/unda-backups/'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3));
            $backup = $backupDirectory.'/'.$relative;

            if (!is_dir(dirname($backup)) && !mkdir(dirname($backup), 0o775, true) && !is_dir(dirname($backup))) {
                throw new GeneratorException(sprintf('Unable to create backup directory for [%s].', $relative));
            }

            if (!copy($target, $backup)) {
                throw new GeneratorException(sprintf('Unable to back up [%s].', $relative));
            }
        }

        $directory = dirname($target);

        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new GeneratorException(sprintf('Unable to create directory [%s].', $directory));
        }

        $temporary = $directory.'/.'.basename($target).'.tmp-'.bin2hex(random_bytes(4));

        if (file_put_contents($temporary, $contents) === false) {
            throw new GeneratorException(sprintf('Unable to write [%s].', $temporary));
        }

        $this->lint($temporary, $relative);

        if (!rename($temporary, $target)) {
            @unlink($temporary);

            throw new GeneratorException(sprintf('Unable to create [%s].', $relative));
        }

        @chmod($target, 0o644);

        return new GeneratedFile(
            $target,
            $relative,
            $namespace,
            $className,
            $backup === null ? null : $this->paths->relative($backup),
        );
    }

    private function lint(string $path, string $relative): void
    {
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (!is_resource($process)) {
            @unlink($path);

            throw new GeneratorException(sprintf('Unable to syntax-check [%s].', $relative));
        }

        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        if ($exit !== 0) {
            @unlink($path);

            throw new GeneratorException(sprintf(
                "Generated file [%s] failed php -l:\n%s",
                $relative,
                trim($stdout.$stderr),
            ));
        }
    }
}
