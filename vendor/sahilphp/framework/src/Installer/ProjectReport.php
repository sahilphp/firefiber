<?php

declare(strict_types=1);

namespace Sahil\Installer;

use Sahil\Ui\InstallReport;
use Sahil\Ui\ProcessResult;
use Sahil\Ui\Profile;

/**
 * What a project-creation run produced.
 */
final class ProjectReport
{
    /** @var list<string> */
    public array $removed = [];

    /** @var list<string> */
    public array $notes = [];

    public int $filesCopied = 0;

    public bool $applicationKeyWritten = false;

    public ?ProcessResult $composerInstall = null;

    public ?ProcessResult $uiDependencyInstall = null;

    public ?InstallReport $ui = null;

    public function __construct(
        public readonly string $projectName,
        public readonly string $destination,
        public readonly Profile $uiProfile,
        public readonly bool $dependenciesInstalled,
    ) {
    }

    /**
     * @return list<string>
     */
    public function lines(): array
    {
        $lines = [
            sprintf('Project:      %s', $this->projectName),
            sprintf('Location:     %s', $this->destination),
            sprintf('UI framework: %s (%s)', $this->uiProfile->label(), $this->uiProfile->value),
            sprintf('Files copied: %d', $this->filesCopied),
        ];

        if ($this->applicationKeyWritten) {
            $lines[] = 'Application key: generated in .env';
        } else {
            $lines[] = 'Application key: already present or not applicable';
        }

        $lines[] = sprintf('PHP dependencies: %s', $this->processSummary($this->composerInstall));
        $lines[] = sprintf('UI dependencies:  %s', $this->processSummary($this->uiDependencyInstall));

        if ($this->removed !== []) {
            $lines[] = 'Removed obsolete UI files:';

            foreach ($this->removed as $file) {
                $lines[] = '  - '.$file;
            }
        }

        foreach ($this->notes as $note) {
            $lines[] = 'note: '.$note;
        }

        $lines[] = '';
        $lines[] = 'Next steps:';
        $lines[] = sprintf('  cd %s', $this->destination);

        if (!$this->dependenciesInstalled) {
            $lines[] = '  composer install';
            $lines[] = '  npm install';
        }

        $lines[] = '  php sahil about';
        $lines[] = '  php sahil ui:build';
        $lines[] = '  php sahil start';

        return $lines;
    }

    private function processSummary(?ProcessResult $result): string
    {
        if (!$this->dependenciesInstalled) {
            return 'skipped (--no-install)';
        }

        if ($result === null) {
            return 'not run';
        }

        return sprintf(
            '%s (%s, exit %d)',
            $result->successful() ? 'installed' : 'failed',
            $result->describe(),
            $result->exitCode,
        );
    }
}
