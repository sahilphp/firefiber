<?php

declare(strict_types=1);

namespace Sahil\Ui;

/**
 * Terminal-ready snapshot produced by `ui:status`.
 */
final class StatusReport
{
    /**
     * @param array<string, bool>                                            $sources
     * @param array<string, bool>                                            $installedPackages
     * @param array<string, array{bytes: int, modified: string}|null>         $assets
     * @param list<string>                                                   $missingAssets
     * @param list<string>                                                   $staleAssets
     * @param list<string>                                                   $issues
     */
    public function __construct(
        public readonly string $basePath,
        public readonly ?Profile $profile,
        public readonly ?string $profileSource,
        public readonly Toolchain $toolchain,
        public readonly array $sources,
        public readonly array $installedPackages,
        public readonly array $assets,
        public readonly array $missingAssets,
        public readonly array $staleAssets,
        public readonly array $issues,
    ) {
    }

    public function ok(): bool
    {
        return $this->issues === [];
    }

    /**
     * @return list<string>
     */
    public function lines(): array
    {
        $lines = [sprintf('UI status: %s', $this->basePath)];
        $lines[] = sprintf(
            '  profile:          %s',
            $this->profile === null
                ? 'unknown'
                : sprintf('%s (%s%s)', $this->profile->label(), $this->profile->value, $this->profileSource === null ? '' : ', from '.$this->profileSource),
        );

        $lines[] = sprintf(
            '  node:             %s',
            $this->toolchain->hasNode()
                ? sprintf('%s (%s)', $this->toolchain->nodeVersion ?? 'version unknown', $this->toolchain->nodePath)
                : 'not found',
        );

        $lines[] = sprintf(
            '  package manager:  %s',
            $this->toolchain->hasPackageManager()
                ? sprintf(
                    '%s %s (%s)',
                    $this->toolchain->packageManager,
                    $this->toolchain->packageManagerVersion ?? 'version unknown',
                    $this->toolchain->packageManagerPath,
                )
                : 'not found',
        );

        $lines[] = sprintf(
            '  lock file:        %s',
            $this->toolchain->lockFile ?? '(none)',
        );

        $sourceCount = count($this->sources);
        $sourcePresent = count(array_filter($this->sources));

        $lines[] = sprintf('  generated files:  %d/%d present', $sourcePresent, $sourceCount);

        foreach ($this->sources as $file => $exists) {
            $lines[] = sprintf('    %-7s %s', $exists ? 'ok' : 'missing', $file);
        }

        $packageCount = count($this->installedPackages);
        $packagePresent = count(array_filter($this->installedPackages));
        $lines[] = sprintf('  npm packages:     %d/%d installed', $packagePresent, $packageCount);

        foreach ($this->installedPackages as $package => $exists) {
            $lines[] = sprintf('    %-7s %s', $exists ? 'ok' : 'missing', $package);
        }

        $lines[] = '  build output:';

        if ($this->assets === []) {
            $lines[] = '    (no profile detected)';
        }

        foreach ($this->assets as $asset => $details) {
            if ($details === null) {
                $lines[] = sprintf('    %-7s %s', 'missing', $asset);

                continue;
            }

            $lines[] = sprintf(
                '    %-7s %s (%s, %s)',
                in_array($asset, $this->staleAssets, true) ? 'stale' : 'ok',
                $asset,
                $this->humanBytes($details['bytes']),
                $details['modified'],
            );
        }

        if ($this->issues === []) {
            $lines[] = '  ready:            yes';
        } else {
            $lines[] = '  issues:';

            foreach ($this->issues as $issue) {
                $lines[] = '    - '.$issue;
            }

            $lines[] = '  ready:            no';
        }

        return $lines;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok(),
            'base_path' => $this->basePath,
            'profile' => $this->profile?->value,
            'profile_source' => $this->profileSource,
            'toolchain' => [
                'node' => $this->toolchain->nodePath === null ? null : [
                    'path' => $this->toolchain->nodePath,
                    'version' => $this->toolchain->nodeVersion,
                ],
                'package_manager' => $this->toolchain->packageManagerPath === null ? null : [
                    'name' => $this->toolchain->packageManager,
                    'path' => $this->toolchain->packageManagerPath,
                    'version' => $this->toolchain->packageManagerVersion,
                ],
                'lock_file' => $this->toolchain->lockFile,
            ],
            'sources' => $this->sources,
            'packages' => $this->installedPackages,
            'assets' => $this->assets,
            'missing_assets' => $this->missingAssets,
            'stale_assets' => $this->staleAssets,
            'issues' => $this->issues,
        ];
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1024 * 1024) {
            return sprintf('%.1f kB', $bytes / 1024);
        }

        return sprintf('%.1f MB', $bytes / (1024 * 1024));
    }
}
