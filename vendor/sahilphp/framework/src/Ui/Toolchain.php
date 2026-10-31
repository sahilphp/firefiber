<?php

declare(strict_types=1);

namespace Sahil\Ui;

/**
 * Result of probing a project's Node.js toolchain.
 */
final class Toolchain
{
    public function __construct(
        public readonly ?string $nodePath = null,
        public readonly ?string $nodeVersion = null,
        public readonly ?string $packageManager = null,
        public readonly ?string $packageManagerPath = null,
        public readonly ?string $packageManagerVersion = null,
        public readonly ?string $lockFile = null,
    ) {
    }

    public function hasNode(): bool
    {
        return $this->nodePath !== null;
    }

    public function hasPackageManager(): bool
    {
        return $this->packageManagerPath !== null;
    }

    public function isReady(): bool
    {
        return $this->hasNode() && $this->hasPackageManager();
    }

    /**
     * The executable to invoke for the detected package manager.
     *
     * @return list<string>
     */
    public function command(): array
    {
        if ($this->packageManagerPath === null) {
            throw new UiException('No package manager is available.');
        }

        return [$this->packageManagerPath];
    }

    /**
     * Actionable guidance for every missing prerequisite.
     *
     * @return list<string>
     */
    public function issues(): array
    {
        $issues = [];

        if (!$this->hasNode()) {
            $issues[] = 'Node.js was not found on PATH. Install Node.js 20.19+ or 22.12+ '
                .'(https://nodejs.org) and re-run this command.';
        }

        if ($this->lockFile !== null && $this->packageManager !== null && !$this->hasPackageManager()) {
            $issues[] = sprintf(
                'The project has [%s] but [%s] is not installed. Install it (for example '
                .'`npm install -g %s`, or `corepack enable %s`) or remove the lock file.',
                $this->lockFile,
                $this->packageManager,
                $this->packageManager,
                $this->packageManager,
            );
        } elseif (!$this->hasPackageManager()) {
            $issues[] = 'No package manager was found on PATH. npm ships with Node.js; '
                .'install npm, pnpm, yarn or bun and re-run this command.';
        }

        return $issues;
    }
}
