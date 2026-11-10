<?php

declare(strict_types=1);

namespace Sahil\Console\Commands;

use Sahil\Console\Command;
use Sahil\Console\CommandInput;
use Sahil\Console\CommandOutput;
use Sahil\Console\ConsoleException;
use Sahil\Encryption\KeyGenerator;
use Sahil\Support\EnvironmentFile;
use Throwable;

/**
 * Project setup commands. They only touch the project directory, so they run
 * before `.env`, the application key or the database exist.
 */
final class ProjectSetupCommand extends Command
{
    /** @var list<string> */
    private const STORAGE_DIRECTORIES = [
        'storage/framework/views',
        'storage/framework/sessions',
        'storage/framework/cache',
        'storage/logs',
        'storage/uploads',
    ];

    public function __construct(private readonly string $command)
    {
    }

    public function name(): string
    {
        return $this->command;
    }

    public function description(): string
    {
        return $this->command === 'key:generate'
            ? 'Write a new APP_KEY to .env (--force replaces an existing key).'
            : 'Create the writable storage directories.';
    }

    public function usage(): string
    {
        return $this->command === 'key:generate'
            ? 'php sahil key:generate [--force] [--path=DIR]'
            : 'php sahil storage:prepare [--path=DIR]';
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

    public function requiresApplication(): bool
    {
        return false;
    }

    /**
     * @return list<string>
     */
    public function examples(): array
    {
        return $this->command === 'key:generate'
            ? ['php sahil key:generate', 'php sahil key:generate --force']
            : ['php sahil storage:prepare'];
    }

    public function execute(CommandInput $input, CommandOutput $output): int
    {
        $input->rejectExtraArguments(0, $this->usage());
        $root = $this->resolvePath($input->option('path') ?? $this->basePath());

        if (!is_dir($root)) {
            throw new ConsoleException(sprintf('project directory [%s] does not exist.', $root));
        }

        if ($this->command === 'key:generate') {
            $file = new EnvironmentFile($root.'/.env');

            if (!$file->exists() && is_file($root.'/.env.example')) {
                if (!@copy($root.'/.env.example', $root.'/.env')) {
                    throw new ConsoleException('unable to create .env from .env.example.');
                }

                $output->line('Created .env from .env.example.');
            }

            $existing = $file->get('APP_KEY');

            if ($existing !== null && trim($existing) !== '' && !$input->flag('force')) {
                throw new ConsoleException('APP_KEY is already set; pass --force to replace it.');
            }

            try {
                $key = KeyGenerator::generate();
                $written = $file->set('APP_KEY', $key);
            } catch (Throwable $error) {
                throw new ConsoleException($error->getMessage());
            }

            $output->line(sprintf('APP_KEY written to %s', $written));
            $output->line('Keep it secret: it protects encrypted and signed values.');

            return 0;
        }

        $created = 0;

        foreach (self::STORAGE_DIRECTORIES as $directory) {
            $path = $root.'/'.$directory;

            if (is_dir($path)) {
                continue;
            }

            if (!@mkdir($path, 0o775, true) && !is_dir($path)) {
                throw new ConsoleException(sprintf('unable to create %s', $directory));
            }

            @chmod($path, 0o775);
            $output->line(sprintf('created %s/', $directory));
            $created++;
        }

        $output->line($created === 0
            ? 'storage: ready.'
            : sprintf('%d director%s created; storage ready.', $created, $created === 1 ? 'y' : 'ies'));

        return 0;
    }

    private function resolvePath(string $path): string
    {
        if (str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1) {
            return rtrim($path, '/');
        }

        return $this->basePath().'/'.trim($path, '/');
    }
}
