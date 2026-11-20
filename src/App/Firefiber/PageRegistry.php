<?php

declare(strict_types=1);

namespace Sahil\Firefiber;

use Sahil\Console\Generator\ApplicationPaths;
use Sahil\Contracts\Foundation\ApplicationContract;

/**
 * Discovers application pages below `App\Firefiber\Pages`.
 *
 * Discovery exists for organizing and resolving page classes — it does NOT
 * publish routes. Every reachable page is registered explicitly through
 * `Link::firefiber('/path', ThePage::class)` in the application's route
 * files, so no page is ever exposed publicly by accident.
 */
final class PageRegistry
{
    public function __construct(
        private readonly ApplicationContract $application,
    ) {
    }

    /**
     * @return list<Page>
     */
    public function pages(): array
    {
        try {
            $paths = ApplicationPaths::for((string) $this->application->basePath());
        } catch (\Sahil\Console\Generator\GeneratorException) {
            return [];
        }

        $namespace = $paths->applicationNamespace().'\Firefiber\Pages';
        [, $directory] = $paths->resolve($namespace);
        $pages = [];

        if (!is_dir($directory)) {
            return [];
        }

        foreach ($this->phpFiles($directory) as $file) {
            $relative = str_replace('\\', '/', substr($file, strlen(rtrim($directory, '/')) + 1));
            $class = $namespace.'\\'.str_replace('/', '\\', substr($relative, 0, -4));

            if (!class_exists($class)) {
                continue;
            }

            if (!is_subclass_of($class, Page::class)) {
                continue;
            }

            /** @var Page $page */
            $page = $this->application->container()->make($class);
            $pages[] = $page;
        }

        usort($pages, static fn (Page $a, Page $b): int => strcmp($a->path(), $b->path()));

        return $pages;
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $item) {
            if ($item instanceof \SplFileInfo && $item->isFile() && str_ends_with($item->getFilename(), '.php')) {
                $files[] = $item->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
