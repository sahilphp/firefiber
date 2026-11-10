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
 * Generate a discoverable Firefiber page and its view.
 */
final class FirefiberPageCommand extends Command
{
    public function name(): string
    {
        return 'firefiber:page';
    }

    public function description(): string
    {
        return 'Generate a discoverable Firefiber page and view.';
    }

    public function usage(): string
    {
        return 'php sahil firefiber:page <Name> [--route=/path] [--components=Hero,Stats] [--example] [--force] [--path=DIR]';
    }

    public function category(): string
    {
        return 'Code Generation';
    }

    public function optionSpecs(): array
    {
        return [
            'route' => 'PATH',
            'components' => 'LIST',
            'example' => null,
            'force' => null,
            'path' => 'DIR',
        ];
    }

    /**
     * @return list<string>
     */
    public function optionHelp(): array
    {
        return [
            '--route=PATH      Page path (default: /firefiber/<kebab-name>).',
            '--components=LIST Comma-separated component names to compose.',
            '--example         Generate Hero and Stats components and compose them.',
            '--force           Overwrite existing generated files after backing them up.',
            '--path=DIR        Application root to generate into (default: this application).',
        ];
    }

    /**
     * @return list<string>
     */
    public function examples(): array
    {
        return [
            'php sahil firefiber:page Demo',
            'php sahil firefiber:page Demo --example',
            'php sahil firefiber:page Admin/Dashboard --route=/admin/dashboard',
        ];
    }

    public function requiresApplication(): bool
    {
        return false;
    }

    public function execute(CommandInput $input, CommandOutput $output): int
    {
        $name = $input->requireArgument(0, 'a page name is required (for example: Demo)');
        $input->rejectExtraArguments(1, $this->usage());

        $root = $input->option('path') ?? $this->basePath();
        if (!str_starts_with($root, DIRECTORY_SEPARATOR) && preg_match('/^[A-Za-z]:[\\\\\/]/', $root) !== 1) {
            $root = $this->basePath().'/'.trim($root, '/');
        }

        $components = [];
        $componentsOption = $input->option('components');
        if ($componentsOption !== null) {
            $components = array_map(static fn (string $value): string => trim($value), explode(',', $componentsOption));
            $components = array_values(array_filter($components));
        }

        try {
            $result = (new FirefiberGenerator($root))->generatePage(
                $name,
                $input->flag('force'),
                $input->option('route'),
                $components,
                $input->flag('example'),
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
