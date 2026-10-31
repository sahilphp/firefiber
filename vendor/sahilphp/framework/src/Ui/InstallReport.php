<?php

declare(strict_types=1);

namespace Sahil\Ui;

/**
 * What an installation did (or would do in `--dry-run` mode).
 */
final class InstallReport
{
    /** @var list<string> */
    public array $created = [];

    /** @var list<string> */
    public array $updated = [];

    /** @var list<string> */
    public array $preserved = [];

    /** @var list<string> */
    public array $backups = [];

    /** @var list<string> */
    public array $notes = [];

    public ?ProcessResult $dependencyInstall = null;

    public function __construct(
        public readonly Profile $profile,
        public readonly string $basePath,
        public readonly bool $dryRun,
    ) {
    }

    /**
     * @return list<string>
     */
    public function lines(): array
    {
        $prefix = $this->dryRun ? 'would ' : '';
        $lines = [sprintf('UI profile: %s (%s)', $this->profile->label(), $this->profile->value)];

        foreach ($this->created as $file) {
            $lines[] = sprintf('%screate   %s', $prefix, $file);
        }

        foreach ($this->updated as $file) {
            $lines[] = sprintf('%supdate   %s', $prefix, $file);
        }

        foreach ($this->preserved as $file) {
            $lines[] = sprintf('preserved %s (existing content kept)', $file);
        }

        foreach ($this->backups as $backup) {
            $lines[] = sprintf('backed up %s', $backup);
        }

        foreach ($this->notes as $note) {
            $lines[] = 'note: '.$note;
        }

        if ($this->dependencyInstall === null) {
            $lines[] = $this->dryRun
                ? 'would skip dependency installation (dry run)'
                : 'dependencies: not installed';
        } else {
            $lines[] = sprintf(
                'dependencies: %s (exit %d)',
                $this->dependencyInstall->successful() ? 'installed' : 'installation failed',
                $this->dependencyInstall->exitCode,
            );
        }

        if (!$this->dryRun) {
            $lines[] = '';
            $lines[] = 'Next:';
            $lines[] = '  bin/sahil ui:build      # production assets';
            $lines[] = '  bin/sahil ui:dev        # Vite development server';
            $lines[] = '  bin/sahil ui:status     # prerequisites and asset state';
        }

        return $lines;
    }
}
