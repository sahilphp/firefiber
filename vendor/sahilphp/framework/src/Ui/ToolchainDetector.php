<?php

declare(strict_types=1);

namespace Sahil\Ui;

/**
 * Detects Node.js, the project's package manager and lock files.
 *
 * Lock-file precedence follows the package manager that produced it, so a
 * pnpm project never silently gets an npm-installed dependency tree. When
 * no lock file exists, npm is preferred because it ships with Node.js.
 */
final class ToolchainDetector
{
    /** @var array<string, string> lock file => package manager */
    private const LOCK_FILES = [
        'pnpm-lock.yaml' => 'pnpm',
        'yarn.lock' => 'yarn',
        'bun.lockb' => 'bun',
        'bun.lock' => 'bun',
        'package-lock.json' => 'npm',
        'npm-shrinkwrap.json' => 'npm',
    ];

    /** @var list<string> */
    private const MANAGERS = ['npm', 'pnpm', 'yarn', 'bun'];

    private ?string $resolvedSearchPath;

    public function __construct(
        private readonly ?ProcessRunner $runner = null,
        ?string $searchPath = null,
    ) {
        $this->resolvedSearchPath = $searchPath;
    }

    public function detect(string $basePath): Toolchain
    {
        $runner = $this->runner ?? new ProcessRunner();

        $nodePath = $this->findExecutable('node');
        $nodeVersion = $nodePath === null ? null : $this->version($runner, $nodePath, $basePath);

        $lockFile = $this->lockFile($basePath);
        $manager = $lockFile === null ? null : self::LOCK_FILES[$lockFile];
        $managerPath = $manager === null ? null : $this->findExecutable($manager);

        if ($manager === null) {
            foreach (self::MANAGERS as $candidate) {
                $candidatePath = $this->findExecutable($candidate);

                if ($candidatePath !== null) {
                    $manager = $candidate;
                    $managerPath = $candidatePath;

                    break;
                }
            }
        }

        $managerVersion = $managerPath === null
            ? null
            : $this->version($runner, $managerPath, $basePath);

        return new Toolchain(
            nodePath: $nodePath,
            nodeVersion: $nodeVersion,
            packageManager: $manager,
            packageManagerPath: $managerPath,
            packageManagerVersion: $managerVersion,
            lockFile: $lockFile,
        );
    }

    private function lockFile(string $basePath): ?string
    {
        foreach (array_keys(self::LOCK_FILES) as $candidate) {
            if (is_file($basePath.'/'.$candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function findExecutable(string $name): ?string
    {
        $searchPath = $this->resolvedSearchPath ?? (getenv('PATH') ?: '');

        if ($searchPath === '') {
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
                $candidate = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$name.$extension;

                if (is_file($candidate) && is_executable($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    private function version(ProcessRunner $runner, string $executable, string $basePath): ?string
    {
        try {
            $result = $runner->run([$executable, '--version'], $basePath);
        } catch (UiException) {
            return null;
        }

        if (!$result->successful()) {
            return null;
        }

        $line = strtok(trim($result->stdout), "\r\n");

        return $line === false ? null : $line;
    }
}
