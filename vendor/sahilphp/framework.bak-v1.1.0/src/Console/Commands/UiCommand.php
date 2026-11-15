<?php

declare(strict_types=1);

namespace Sahil\Console\Commands;

use Sahil\Console\Command;
use Sahil\Console\CommandInput;
use Sahil\Console\CommandOutput;
use Sahil\Ui\UiConsole;

/**
 * Frontend commands are build tools: they need the project directory, not a
 * booted application.
 */
final class UiCommand extends Command
{
    public function __construct(private readonly string $command)
    {
    }

    public function name(): string
    {
        return $this->command;
    }

    public function description(): string
    {
        return match ($this->command) {
            'ui:install' => 'Install a frontend asset profile (Tailwind v4, Bootstrap 5, both or plain CSS).',
            'ui:dev' => 'Run the Vite development server.',
            'ui:build' => 'Build production frontend assets.',
            'ui:status' => 'Report frontend prerequisites, sources and outputs.',
            'ui:theme' => 'Generate optional theme files from neutral tokens.',
            default => 'Frontend command.',
        };
    }

    public function usage(): string
    {
        return 'php sahil '.$this->command.' [options]';
    }

    public function category(): string
    {
        return 'Frontend';
    }

    public function optionSpecs(): array
    {
        return [
            'path' => 'DIR',
            'profile' => 'NAME',
            'ui' => 'NAME',
            'source' => 'FILE',
            'dry-run' => null,
            'merge' => null,
            'overwrite' => null,
            'force' => null,
            'no-install' => null,
            'json' => null,
            'clean' => null,
            'host' => 'HOST',
            'port' => 'PORT',
            'no-interaction' => null,
            'interactive' => null,
        ];
    }

    public function requiresApplication(): bool
    {
        return false;
    }

    /**
     * @return list<string>
     */
    public function optionHelp(): array
    {
        return [
            '--path=DIR          Project root to operate on (default: this application).',
            '--profile=NAME      Frontend asset profile.',
            '--ui=NAME           UI integration name.',
            '--source=FILE       Theme token file for ui:theme.',
            '--dry-run           Print the plan without writing anything.',
            '--merge             Merge managed entries instead of replacing files.',
            '--overwrite         Replace managed files (backups are kept).',
            '--force             Force the operation.',
            '--no-install        Do not install npm packages.',
            '--json              Print a machine-readable report.',
            '--clean             Remove the build directory before building.',
            '--host=HOST         Development server interface.',
            '--port=PORT         Development server port.',
            '--no-interaction    Run without prompts.',
            '--interactive       Force interactive prompts.',
        ];
    }

    /**
     * @return list<string>
     */
    public function examples(): array
    {
        return [
            'php sahil ui:install tailwind',
            'php sahil ui:dev',
            'php sahil ui:build --clean',
            'php sahil ui:status --json',
        ];
    }

    public function execute(CommandInput $input, CommandOutput $output): int
    {
        return (new UiConsole())->run($this->command, $input->tokens(), $this->basePath());
    }
}
