<?php

declare(strict_types=1);

namespace Sahil\Console\Commands;

use Sahil\Console\Command;
use Sahil\Console\CommandInput;
use Sahil\Console\CommandOutput;
use Sahil\Console\ConsoleException;

/**
 * Publishes the framework's error pages into the application.
 *
 * The framework ships a designed page for every common status (404, 500,
 * 403, ...) and renders it when nothing else exists. Publishing copies those
 * templates to `resources/views/errors/`, where the application's view roots
 * are searched first — so the published copy takes over, and editing it needs
 * no framework change. This mirrors Laravel's
 * `vendor:publish --tag=laravel-errors`.
 *
 * Only project files are touched, so the command works before the application
 * can boot.
 */
final class ErrorPublishCommand extends Command
{
    public function name(): string
    {
        return 'error:publish';
    }

    public function description(): string
    {
        return 'Publish the framework error pages (404, 500, ...) so you can customize them.';
    }

    public function usage(): string
    {
        return 'php sahil error:publish [--force] [--path=DIR]';
    }

    public function category(): string
    {
        return 'Application';
    }

    public function optionSpecs(): array
    {
        return [
            'force' => null,
            'path' => 'DIR',
        ];
    }

    public function optionHelp(): array
    {
        return [
            '--force        Replace error pages that already exist.',
            '--path=DIR     Project root to publish into (default: this application).',
        ];
    }

    public function examples(): array
    {
        return [
            'php sahil error:publish',
            'php sahil error:publish --force',
        ];
    }

    public function requiresApplication(): bool
    {
        return false;
    }

    public function execute(CommandInput $input, CommandOutput $output): int
    {
        $input->rejectExtraArguments(0, $this->usage());

        $root = $this->resolvePath($input->option('path') ?? $this->basePath());

        if (!is_dir($root)) {
            throw new ConsoleException(sprintf('project directory [%s] does not exist.', $root));
        }

        $source = self::sourceDirectory();

        if (!is_dir($source)) {
            throw new ConsoleException(sprintf(
                'the framework error pages are missing from this installation (expected [%s]).',
                $source,
            ));
        }

        $force = $input->flag('force');

        /** @var list<string> $templates */
        $templates = glob($source.'/*.sahil.php') ?: [];

        if ($templates === []) {
            throw new ConsoleException('the framework ships no error pages (empty resources/views/errors).');
        }

        sort($templates);

        $target = $root.'/resources/views/errors';

        if (!is_dir($target) && !mkdir($target, 0o777, true) && !is_dir($target)) {
            throw new ConsoleException(sprintf('unable to create [%s].', $target));
        }

        $written = 0;
        $kept = 0;

        foreach ($templates as $template) {
            $destination = $target.'/'.basename($template);

            if (is_file($destination) && !$force) {
                $output->line('  keep   resources/views/errors/'.basename($template));
                $kept++;

                continue;
            }

            if (!copy($template, $destination)) {
                throw new ConsoleException(sprintf('unable to write [%s].', $destination));
            }

            $output->line('  write  resources/views/errors/'.basename($template));
            $written++;
        }

        $output->line('');
        $output->line(sprintf(
            'Published %d error page(s) to resources/views/errors/.',
            $written,
        ));

        if ($kept > 0) {
            $output->line(sprintf(
                'Kept %d existing page(s); run with --force to replace them.',
                $kept,
            ));
        }

        return 0;
    }

    /**
     * Absolute path of the framework's own error templates.
     */
    public static function sourceDirectory(): string
    {
        return dirname(__DIR__, 3).'/resources/views/errors';
    }

    private function resolvePath(string $path): string
    {
        if ($path === '' || str_starts_with($path, '/')) {
            return rtrim($path, '/');
        }

        $resolved = realpath($this->basePath().'/'.$path);

        return $resolved === false ? $this->basePath().'/'.rtrim($path, '/') : $resolved;
    }
}
