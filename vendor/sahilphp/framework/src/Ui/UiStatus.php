<?php

declare(strict_types=1);

namespace Sahil\Ui;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Read-only inspection of a project's UI installation.
 */
final class UiStatus
{
    public function __construct(
        private readonly ToolchainDetector $detector = new ToolchainDetector(),
        private readonly ProfileDetector $profiles = new ProfileDetector(),
    ) {
    }

    public function inspect(string $basePath): StatusReport
    {
        if (!is_dir($basePath)) {
            throw new UiException(sprintf('Project directory [%s] does not exist.', $basePath));
        }

        $resolved = realpath($basePath);

        if ($resolved === false) {
            throw new UiException(sprintf('Unable to resolve project directory [%s].', $basePath));
        }

        [$profile, $profileSource] = $this->detectProfile($resolved);
        $toolchain = $this->detector->detect($resolved);

        $sources = [];
        $missingSources = [];

        foreach ($profile?->sourceFiles() ?? [] as $file) {
            $exists = is_file($resolved.'/'.$file);
            $sources[$file] = $exists;

            if (!$exists) {
                $missingSources[] = $file;
            }
        }

        $requiredPackages = $profile?->requiredPackages() ?? [];
        $installedPackages = [];
        $missingPackages = [];

        foreach ($requiredPackages as $package) {
            $exists = is_file($resolved.'/node_modules/'.$package.'/package.json');
            $installedPackages[$package] = $exists;

            if (!$exists) {
                $missingPackages[] = $package;
            }
        }

        $assets = [];
        $missingAssets = [];
        $staleAssets = [];

        $newestSource = $this->newestMtime($resolved, $profile?->sourceFiles() ?? []);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        foreach ($profile?->expectedAssets() ?? [] as $asset) {
            $path = $resolved.'/'.$asset;

            if (!is_file($path)) {
                $missingAssets[] = $asset;
                $assets[$asset] = null;

                continue;
            }

            $mtime = (int) filemtime($path);
            $assets[$asset] = [
                'bytes' => (int) filesize($path),
                'modified' => $now->setTimestamp($mtime)->format(DATE_ATOM),
            ];

            if ($newestSource !== null && $mtime < $newestSource) {
                $staleAssets[] = $asset;
            }
        }

        $issues = [];

        if ($profile === null) {
            $issues[] = 'No UI profile is recorded and none could be inferred. '
                .'Run `bin/sahil ui:install --profile=tailwind|bootstrap|both|none`.';
        }

        foreach ($toolchain->issues() as $issue) {
            $issues[] = $issue;
        }

        if ($missingSources !== []) {
            $issues[] = sprintf(
                'Missing generated source file(s): %s. Re-run `bin/sahil ui:install`.',
                implode(', ', $missingSources),
            );
        }

        if ($missingPackages !== []) {
            $issues[] = sprintf(
                'Missing npm package(s): %s. Run `bin/sahil ui:install` or `%s install`.',
                implode(', ', $missingPackages),
                $toolchain->packageManager ?? 'npm',
            );
        }

        if ($missingAssets !== []) {
            $issues[] = sprintf(
                'Missing build output(s): %s. Run `bin/sahil ui:build`.',
                implode(', ', $missingAssets),
            );
        }

        if ($staleAssets !== []) {
            $issues[] = sprintf(
                'Build output is older than the source file(s) it was built from: %s. Run `bin/sahil ui:build`.',
                implode(', ', $staleAssets),
            );
        }

        return new StatusReport(
            basePath: $resolved,
            profile: $profile,
            profileSource: $profileSource,
            toolchain: $toolchain,
            sources: $sources,
            installedPackages: $installedPackages,
            assets: $assets,
            missingAssets: $missingAssets,
            staleAssets: $staleAssets,
            issues: $issues,
        );
    }

    /**
     * Detect the project's profile from `.sahil-ui.json`, falling back to
     * package.json dependencies.
     *
     * @return array{0: ?Profile, 1: ?string}
     */
    public function detectProfile(string $basePath): array
    {
        return $this->profiles->detect($basePath);
    }

    /**
     * @param list<string> $files
     */
    private function newestMtime(string $basePath, array $files): ?int
    {
        $newest = null;

        foreach ($files as $file) {
            $path = $basePath.'/'.$file;

            if (!is_file($path)) {
                continue;
            }

            $mtime = (int) filemtime($path);

            if ($newest === null || $mtime > $newest) {
                $newest = $mtime;
            }
        }

        return $newest;
    }
}
