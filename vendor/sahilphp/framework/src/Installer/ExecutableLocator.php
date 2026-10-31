<?php

declare(strict_types=1);

namespace Sahil\Installer;

/**
 * Finds executables on PATH without invoking a shell.
 *
 * The installer uses this for Composer, which is a project-creation
 * prerequisite rather than a frontend toolchain concern.
 */
final class ExecutableLocator
{
    private readonly ?string $searchPath;

    public function __construct(?string $searchPath = null)
    {
        $this->searchPath = $searchPath;
    }

    public function find(string $name): ?string
    {
        $searchPath = $this->searchPath ?? (getenv('PATH') ?: '');

        if ($searchPath === '' || $name === '' || str_contains($name, DIRECTORY_SEPARATOR)) {
            return null;
        }

        $extensions = PHP_OS_FAMILY === 'Windows'
            ? ['.cmd', '.exe', '.bat', '']
            : [''];

        foreach (explode(PATH_SEPARATOR, $searchPath) as $directory) {
            $directory = trim($directory);

            if ($directory === '') {
                continue;
            }

            foreach ($extensions as $extension) {
                $candidate = rtrim($directory, DIRECTORY_SEPARATOR)
                    .DIRECTORY_SEPARATOR.$name.$extension;

                if (is_file($candidate) && is_executable($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }
}
