<?php

declare(strict_types=1);

namespace Sahil\Console\Commands;

use Sahil\Console\Command;
use Sahil\Console\CommandInput;
use Sahil\Console\CommandOutput;
use Sahil\Console\ConsoleException;
use Sahil\Ui\Profile;
use Sahil\Ui\ProfileDetector;
use Throwable;

/**
 * Scaffolds the complete authentication implementation (model, migrations,
 * controllers, middleware, routes and views).
 *
 * This command only touches project files, so it works before the application
 * is configured. Existing files are kept unless `--force` is passed.
 */
final class AuthCommand extends Command
{
    public function __construct(
        private readonly string $command,
        private readonly bool $isHidden = false,
    ) {
    }

    public function name(): string
    {
        return $this->command;
    }

    public function description(): string
    {
        return 'Generate the authentication model, migrations, controllers, middleware, routes and views.';
    }

    public function usage(): string
    {
        return 'php sahil '.$this->command.' [--ui=tailwind|bootstrap] [--force] [--dry-run] [--path=DIR]';
    }

    public function category(): string
    {
        return 'Code Generation';
    }

    public function optionSpecs(): array
    {
        return [
            'ui' => 'NAME',
            'profile' => 'NAME',
            'force' => null,
            'dry-run' => null,
            'path' => 'DIR',
        ];
    }

    public function requiresApplication(): bool
    {
        return false;
    }

    public function hidden(): bool
    {
        return $this->isHidden;
    }

    /**
     * @return list<string>
     */
    public function optionHelp(): array
    {
        return [
            '--ui=NAME        tailwind or bootstrap (detected when omitted).',
            '--force          Replace files that already exist (originals are backed up).',
            '--dry-run        Print the plan without writing anything.',
            '--path=DIR       Project root to scaffold (default: this application).',
        ];
    }

    /**
     * @return list<string>
     */
    public function examples(): array
    {
        return [
            'php sahil '.$this->command,
            'php sahil '.$this->command.' --ui=bootstrap',
            'php sahil '.$this->command.' --force',
        ];
    }

    public function execute(CommandInput $input, CommandOutput $output): int
    {
        $input->rejectExtraArguments(0, $this->usage());
        $root = $this->resolvePath($input->option('path') ?? $this->basePath());

        if (!is_dir($root)) {
            throw new ConsoleException(sprintf('project directory [%s] does not exist.', $root));
        }

        $profile = null;
        $requested = $input->option('ui') ?? $input->option('profile');

        if ($requested !== null) {
            $profile = Profile::resolve($requested);

            if ($profile === null) {
                throw new ConsoleException(sprintf('unknown UI profile [%s]; use tailwind or bootstrap.', $requested));
            }
        }

        if ($profile === null) {
            [$detected] = (new ProfileDetector())->detect($root);

            if ($detected !== null && $detected !== Profile::None) {
                $profile = $detected;
            }
        }

        if ($profile === null || $profile === Profile::None) {
            $profile = Profile::Tailwind;
        }

        $class = 'App\\Support\\AuthScaffolder';

        if (!class_exists($class)) {
            throw new ConsoleException('the authentication scaffolder is missing from this installation (expected app/Support/AuthScaffolder.php).');
        }

        $force = $input->flag('force');
        $dryRun = $input->flag('dry-run');

        try {
            /** @var object $scaffolder */
            $scaffolder = new $class($this->basePath().'/.sahil/scaffolds/auth');

            if (!method_exists($scaffolder, 'scaffold')) {
                throw new ConsoleException(sprintf('%s does not expose scaffold().', $class));
            }

            /** @var array{profile: string, created: list<string>, updated: list<string>, skipped: list<string>, backups: list<string>, warnings: list<string>} $report */
            $report = $scaffolder->scaffold($root, $profile, $force, $dryRun);
        } catch (ConsoleException $error) {
            throw $error;
        } catch (Throwable $error) {
            throw new ConsoleException($error->getMessage());
        }

        $output->line($dryRun
            ? sprintf('Would scaffold the authentication implementation (%s profile).', $report['profile'])
            : 'Auth scaffolding complete.');

        foreach ($report['created'] as $relative) {
            $output->line(sprintf('  created  %s', $relative));
        }

        foreach ($report['updated'] as $relative) {
            $output->line(sprintf('  updated  %s', $relative));
        }

        foreach ($report['skipped'] as $relative) {
            $output->line(sprintf('  skipped  %s (exists; --force overwrites it)', $relative));
        }

        foreach ($report['warnings'] as $warning) {
            $output->line(sprintf('  warning  %s', $warning));
        }

        foreach ($report['backups'] as $relative) {
            $output->line(sprintf('  backup   %s', $relative));
        }

        $output->line();
        $output->line('Files created:');

        foreach ($report['created'] as $relative) {
            $output->line(sprintf('  %s', $relative));
        }

        if (!$dryRun && ($report['created'] !== [] || $report['updated'] !== [])) {
            $output->line();
            $output->line('Next steps:');
            $output->line('  1. Run: php sahil migrate');
            $output->line('  2. Visit /register to create your first user.');
        }

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
