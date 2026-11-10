<?php

declare(strict_types=1);

namespace Sahil\Firefiber\Console\Commands;

use Sahil\Console\Command;
use Sahil\Console\CommandInput;
use Sahil\Console\CommandOutput;
use Sahil\Console\ConsoleException;
use Sahil\Console\Generator\GeneratedFile;
use Sahil\Console\Generator\GeneratorException;
use Sahil\Firefiber\Generator\FirefiberGenerator;

/**
 * Generate a reusable Firefiber component.
 */
final class FirefiberComponentCommand extends Command
{
    public function name(): string
    {
        return 'firefiber:component';
    }

    public function description(): string
    {
        return 'Generate a reusable Firefiber component.';
    }

    public function usage(): string
    {
        return 'php sahil firefiber:component <Name> [--force] [--path=DIR] [--inline]';
    }

    public function category(): string
    {
        return 'Code Generation';
    }

    public function optionSpecs(): array
    {
        return [
            'force' => null,
            'path' => 'DIR',
            'inline' => null,
        ];
    }

    /**
     * @return list<string>
     */
    public function optionHelp(): array
    {
        return [
            '--force    Overwrite an existing file after backing it up.',
            '--path=DIR Application root to generate into (default: this application).',
            '--inline  Generate an inline component (default: standalone component).',
        ];
    }

    /**
     * @return list<string>
     */
    public function examples(): array
    {
        return [
            'php sahil firefiber:component Counter',
            'php sahil firefiber:component Counter --inline',
            'php sahil firefiber:component Admin/Stats',
        ];
    }

    public function requiresApplication(): bool
    {
        return false;
    }

    public function execute(CommandInput $input, CommandOutput $output): int
    {
        $name = $input->requireArgument(0, 'a component name is required (for example: Counter)');
        $input->rejectExtraArguments(1, $this->usage());

        $root = $input->option('path') ?? $this->basePath();
        if (!str_starts_with($root, DIRECTORY_SEPARATOR) && preg_match('/^[A-Za-z]:[\\\\\/]/', $root) !== 1) {
            $root = $this->basePath().'/'.trim($root, '/');
        }

        try {
            $result = (new FirefiberGenerator($root))->generateComponent(
                $name,
                $input->flag('force'),
                'counter',
                $input->flag('inline'),
            );
        } catch (GeneratorException $exception) {
            throw new ConsoleException($exception->getMessage());
        }

        foreach ($result->files as $file) {
            $this->report($file, $output);
        }

        return 0;
    }

    private function report(GeneratedFile $file, CommandOutput $output): void
    {
        $output->line(sprintf(
            '%s %s',
            $file->backupPath === null ? 'Created' : 'Updated',
            $file->relativePath,
        ));

        if ($file->namespace !== null) {
            $output->line('  namespace '.$file->namespace);
        }

        if ($file->backupPath !== null) {
            $output->line('  backup    '.$file->backupPath);
        }
    }
}
