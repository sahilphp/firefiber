<?php

declare(strict_types=1);

namespace Sahil\Console\Generator;

/**
 * Resolves namespaces and directories from the consuming application's own
 * Composer PSR-4 map. Nothing here assumes the application is called `App`.
 */
final class ApplicationPaths
{
    /**
     * @param array<string, string|list<string>> $psr4
     */
    private function __construct(
        private readonly string $root,
        private readonly array $psr4,
    ) {
    }

    public static function for(string $root): self
    {
        $resolved = realpath($root);

        if ($resolved === false || !is_dir($resolved)) {
            throw new GeneratorException(sprintf('Application directory [%s] does not exist.', $root));
        }

        $composer = $resolved.'/composer.json';

        if (!is_file($composer)) {
            throw new GeneratorException(sprintf(
                'composer.json is missing from [%s]; run this command from an application root.',
                $resolved,
            ));
        }

        $contents = file_get_contents($composer);

        if ($contents === false) {
            throw new GeneratorException(sprintf('Unable to read [%s].', $composer));
        }

        $decoded = json_decode($contents, true);

        if (!is_array($decoded)) {
            throw new GeneratorException(sprintf('[%s] is not valid JSON.', $composer));
        }

        $mapping = $decoded['autoload']['psr-4'] ?? null;

        if (!is_array($mapping) || $mapping === []) {
            throw new GeneratorException(
                'composer.json has no autoload.psr-4 mapping; add one (for example "App\\\\": "app/").',
            );
        }

        /** @var array<string, string|list<string>> $mapping */
        return new self($resolved, $mapping);
    }

    public function root(): string
    {
        return $this->root;
    }

    /**
     * The root application namespace, derived from PSR-4 (not hardcoded).
     */
    public function applicationNamespace(): string
    {
        foreach ($this->psr4 as $namespace => $directories) {
            $namespace = trim((string) $namespace, '\\');
            $directory = $this->firstDirectory($directories);

            if ($directory === '' || str_starts_with($directory, 'vendor/')) {
                continue;
            }

            if (in_array($namespace, ['Sahil', 'SahilPHP', 'Tests'], true)) {
                continue;
            }

            return $namespace;
        }

        throw new GeneratorException(
            'Unable to derive the application namespace from composer.json autoload.psr-4.',
        );
    }

    /**
     * Resolve a fully qualified namespace to [namespace, absolute directory].
     *
     * The longest matching PSR-4 prefix wins, so both `App\` and a narrower
     * `App\Http\Controllers\` mapping are honoured.
     *
     * @return array{0: string, 1: string}
     */
    public function resolve(string $namespace): array
    {
        $namespace = trim($namespace, '\\');
        $best = null;
        $bestLength = -1;

        foreach ($this->psr4 as $prefix => $directories) {
            $prefix = trim((string) $prefix, '\\');
            $directory = $this->firstDirectory($directories);

            if ($directory === '') {
                continue;
            }

            if ($namespace !== $prefix && !str_starts_with($namespace, $prefix.'\\')) {
                continue;
            }

            if (strlen($prefix) <= $bestLength) {
                continue;
            }

            $bestLength = strlen($prefix);
            $best = [$prefix, $directory];
        }

        if ($best === null) {
            throw new GeneratorException(sprintf(
                'composer.json does not map the namespace [%s] under autoload.psr-4.',
                $namespace,
            ));
        }

        [$prefix, $directory] = $best;
        $relative = $namespace === $prefix ? '' : substr($namespace, strlen($prefix) + 1);
        $relativePath = $relative === '' ? '' : '/'.str_replace('\\', '/', $relative);

        return [$namespace, $this->root.'/'.rtrim($directory, '/').$relativePath];
    }

    /**
     * Path relative to the application root, with forward slashes.
     */
    public function relative(string $absolutePath): string
    {
        $prefix = $this->root.DIRECTORY_SEPARATOR;

        if (!str_starts_with($absolutePath, $prefix)) {
            throw new GeneratorException(sprintf('[%s] is outside the application.', $absolutePath));
        }

        return str_replace('\\', '/', substr($absolutePath, strlen($prefix)));
    }

    /**
     * Reject any destination that would escape the application root.
     */
    public function assertInside(string $absolutePath): void
    {
        $root = rtrim($this->root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        if (!str_starts_with($absolutePath, $root)) {
            throw new GeneratorException(sprintf('Refusing to write outside the application: [%s].', $absolutePath));
        }

        $segments = explode('/', str_replace('\\', '/', substr($absolutePath, strlen($root))));

        foreach ($segments as $segment) {
            if ($segment === '..') {
                throw new GeneratorException(sprintf('Refusing path traversal in [%s].', $absolutePath));
            }
        }
    }

    /**
     * @param string|list<string> $directories
     */
    private function firstDirectory(string|array $directories): string
    {
        $directory = is_array($directories) ? (string) ($directories[0] ?? '') : $directories;

        return trim(str_replace('\\', '/', $directory), '/');
    }
}
