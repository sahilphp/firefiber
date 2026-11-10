<?php

declare(strict_types=1);

namespace Sahil\Firefiber\Generator;

use Sahil\Console\Generator\ApplicationPaths;
use Sahil\Console\Generator\GeneratedFile;
use Sahil\Console\Generator\GeneratorException;
use Sahil\Console\Generator\GeneratorResult;

/**
 * Package-owned code generation for conventional Firefiber locations.
 *
 * This intentionally uses only the application-path service from the
 * framework generator rather than coupling the package to its private
 * file writer. Every destination is validated, confined to the application
 * root, syntax-checked, and never overwritten without --force.
 */
final class FirefiberGenerator
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

    private readonly ApplicationPaths $paths;

    public function __construct(string $basePath)
    {
        $this->paths = ApplicationPaths::for($basePath);
    }

    public function generateComponent(string $name, bool $force, string $kind = 'counter'): GeneratorResult
    {
        [$class, $subNamespace] = $this->splitClassName($name);
        $namespace = $this->paths->applicationNamespace().'\Firefiber\Components';

        if ($subNamespace !== []) {
            $namespace .= '\\'.implode('\\', $subNamespace);
        }

        [, $directory] = $this->paths->resolve($namespace);
        $target = rtrim($directory, '/').'/'.$class.'.php';

        return new GeneratorResult([
            $this->write($target, Stubs::component($namespace, $class, $kind), $force, $class, $namespace),
        ]);
    }

    /**
     * @param list<string> $components
     */
    public function generatePage(
        string $name,
        bool $force,
        ?string $route = null,
        array $components = [],
        bool $example = false,
    ): GeneratorResult {
        [$class, $subNamespace] = $this->splitClassName($name);
        $class = str_ends_with($class, 'Page') ? $class : $class.'Page';
        $namespace = $this->paths->applicationNamespace().'\Firefiber\Pages';

        if ($subNamespace !== []) {
            $namespace .= '\\'.implode('\\', $subNamespace);
        }

        [, $directory] = $this->paths->resolve($namespace);
        $target = rtrim($directory, '/').'/'.$class.'.php';
        $view = $this->viewName($subNamespace, $class);
        $viewPath = trim($view, '/');
        if (str_ends_with($viewPath, '.sahil.php')) {
            $viewPath = substr($viewPath, 0, -strlen('.sahil.php'));
        }
        $viewName = str_replace('/', '.', $viewPath);
        $route ??= $this->defaultRoute($subNamespace, $class);
        $this->validateRoute($route);
        $generatedComponents = $example ? ['Hero', 'Stats'] : $components;

        if ($example) {
            foreach ($generatedComponents as $componentName) {
                [$componentClass] = $this->splitClassName($componentName);
                $this->assertGenerateableComponent($componentClass, $force);
            }
        }

        $viewTarget = $this->paths->root().'/resources/views/'.trim($view, '/');
        $this->assertGenerateable($target, $force);
        $this->assertGenerateable($viewTarget, $force);

        $files = [];

        if ($example) {
            foreach ($generatedComponents as $componentName) {
                [$componentClass] = $this->splitClassName($componentName);
                $files[] = $this->generateComponent(
                    $componentClass,
                    $force,
                    strtolower($componentClass),
                )->files[0];
            }
        }

        $componentSpecs = [];
        foreach ($generatedComponents as $componentName) {
            [$componentClass, $componentSubNamespace] = $this->splitClassName($componentName);
            $componentNamespace = $this->paths->applicationNamespace().'\Firefiber\Components';
            if ($componentSubNamespace !== []) {
                $componentNamespace .= '\\'.implode('\\', $componentSubNamespace);
            }
            $componentSpecs[] = [$componentNamespace, $componentClass];
        }

        $files[] = $this->write(
            $target,
            Stubs::page($namespace, $class, $route, $viewName, $componentSpecs),
            $force,
            $class,
            $namespace,
        );
        $files[] = $this->write(
            $viewTarget,
            Stubs::pageView($class),
            $force,
            $class,
            null,
        );

        return new GeneratorResult($files);
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

    private function assertGenerateableComponent(string $class, bool $force): void
    {
        $namespace = $this->paths->applicationNamespace().'\Firefiber\Components';
        [, $directory] = $this->paths->resolve($namespace);
        $this->assertGenerateable(rtrim($directory, '/').'/'.$class.'.php', $force);
    }

    private function assertGenerateable(string $target, bool $force): void
    {
        $this->paths->assertInside($target);

        if (!$force && is_file($target)) {
            throw new GeneratorException(sprintf(
                '%s already exists; pass --force to overwrite it.',
                $this->paths->relative($target),
            ));
        }
    }

    private function write(
        string $target,
        string $contents,
        bool $force,
        string $className,
        ?string $namespace,
    ): GeneratedFile {
        $this->paths->assertInside($target);
        $relative = $this->paths->relative($target);
        $contents = rtrim($contents, "\r\n")."\n";
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

    private function viewName(array $subNamespace, string $class): string
    {
        $base = str_ends_with($class, 'Page') ? substr($class, 0, -4) : $class;
        $segments = [...$subNamespace, $base];
        $segments = array_map(static fn (string $segment): string => strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $segment)), $segments);

        return 'firefiber/pages/'.implode('/', $segments).'.sahil.php';
    }

    /**
     * @param list<string> $subNamespace
     */
    private function defaultRoute(array $subNamespace, string $class): string
    {
        $base = str_ends_with($class, 'Page') ? substr($class, 0, -4) : $class;
        $segments = [...$subNamespace, $base];
        $segments = array_map(static fn (string $segment): string => strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $segment)), $segments);

        return '/firefiber/'.implode('/', $segments);
    }

    private function validateRoute(string $route): void
    {
        if ($route === '' || !str_starts_with($route, '/') || str_contains($route, '..') || str_contains($route, '//')) {
            throw new GeneratorException(sprintf('Invalid Firefiber page route [%s].', $route));
        }

        foreach (explode('/', trim($route, '/')) as $segment) {
            if ($segment !== '' && preg_match('/^[A-Za-z0-9._-]+$/', $segment) !== 1) {
                throw new GeneratorException(sprintf('Invalid Firefiber page route segment [%s].', $segment));
            }
        }
    }
}
