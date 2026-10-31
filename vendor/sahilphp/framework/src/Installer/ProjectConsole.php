<?php

declare(strict_types=1);

namespace Sahil\Installer;

use Sahil\Ui\ProcessFailureException;
use Sahil\Ui\Profile;
use Sahil\Ui\UiException;
use Throwable;

/**
 * Terminal workflow for creating a new SahilPHP application.
 *
 * The command is intentionally available from the starter-kit's existing
 * `bin/sahil` entry point: no global binary and no second CLI framework.
 */
final class ProjectConsole
{
    public function __construct(
        private readonly ?ProjectInstaller $installer = null,
        private readonly ?ConsoleIO $io = null,
    ) {
    }

    /**
     * @param list<string> $args arguments after `new`
     */
    public function run(array $args, string $sourcePath, string $workingDirectory): int
    {
        $io = $this->io ?? ConsoleIO::standard();

        try {
            $options = $this->parseOptions($args);

            if ($options['help']) {
                $this->printHelp($io);

                return 0;
            }

            if ($options['interactive']) {
                $io->forceInteractive();
            }

            $name = $this->projectName($options, $io);
            $ui = $this->selectedUi($options['ui'], $options['no_interaction'], $io);
            $destination = $options['path'] === null
                ? rtrim($workingDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$name
                : $this->absolutePath($options['path'], $workingDirectory);

            $io->line('SahilPHP Installer');
            $io->line();
            $io->line('Create a new SahilPHP application');
            $io->line();
            $io->line(sprintf('Project name:     %s', $name));
            $io->line(sprintf('Destination:      %s', $destination));
            $io->line(sprintf('UI framework:     %s', $ui->label()));
            $io->line();

            $report = $this->installer()->install(
                new ProjectOptions(
                    sourcePath: $sourcePath,
                    destination: $destination,
                    projectName: $name,
                    ui: $ui,
                    installDependencies: !$options['no_install'],
                    installDevDependencies: !$options['no_dev'],
                ),
                static function (string $message) use ($io): void {
                    $io->line($message);
                },
            );

            $io->line();
            $io->line('Project created successfully.');
            $io->line();

            foreach ($report->lines() as $line) {
                $io->line($line);
            }

            return 0;
        } catch (ProcessFailureException $failure) {
            $io->error(sprintf('new: %s', $failure->getMessage()));

            return $failure->processExitCode;
        } catch (InstallerException|UiException $failure) {
            $io->error(sprintf('new: %s', $failure->getMessage()));

            return 1;
        } catch (Throwable $unexpected) {
            $io->error(sprintf('new: %s', $unexpected->getMessage()));

            if (getenv('SAHIL_DEBUG') === '1') {
                $io->error($unexpected::class);
            }

            return 1;
        }
    }

    /**
     * @param list<string> $args
     *
     * @return array{positional: list<string>, path: ?string, ui: ?string, no_install: bool, no_dev: bool, no_interaction: bool, interactive: bool, help: bool}
     */
    private function parseOptions(array $args): array
    {
        $options = [
            'positional' => [],
            'path' => null,
            'ui' => null,
            'no_install' => false,
            'no_dev' => false,
            'no_interaction' => false,
            'interactive' => false,
            'help' => false,
        ];

        for ($index = 0; $index < count($args); $index++) {
            $arg = $args[$index];

            if ($arg === '') {
                continue;
            }

            if (!str_starts_with($arg, '-')) {
                $options['positional'][] = $arg;

                continue;
            }

            $name = $arg;
            $value = null;

            if (str_contains($arg, '=')) {
                [$name, $value] = explode('=', $arg, 2);
            }

            switch ($name) {
                case '--help':
                case '-h':
                    $options['help'] = true;

                    break;
                case '--no-install':
                    $options['no_install'] = true;

                    break;
                case '--no-dev':
                    $options['no_dev'] = true;

                    break;
                case '--no-interaction':
                case '-n':
                    $options['no_interaction'] = true;

                    break;
                case '--interactive':
                    $options['interactive'] = true;

                    break;
                case '--path':
                case '--ui':
                case '--profile':
                case '--ui-framework':
                    if ($value === null) {
                        $next = $args[$index + 1] ?? null;

                        if ($next === null || str_starts_with($next, '--')) {
                            throw new InstallerException(sprintf('new: %s requires a value.', $name));
                        }

                        $value = $next;
                        $index++;
                    }

                    $value = trim($value);

                    if ($value === '') {
                        throw new InstallerException(sprintf('new: %s requires a non-empty value.', $name));
                    }

                    $key = $name === '--path' ? 'path' : 'ui';

                    if ($key === 'ui' && $options['ui'] !== null && $options['ui'] !== $value) {
                        throw new InstallerException(sprintf(
                            'new: conflicting UI frameworks [%s] and [%s]. Pass only one.',
                            $options['ui'],
                            $value,
                        ));
                    }

                    $options[$key] = $value;

                    break;
                default:
                    throw new InstallerException(sprintf('new: unknown option [%s].', $name));
            }
        }

        if (count($options['positional']) > 1) {
            throw new InstallerException(sprintf(
                'new: unexpected argument [%s].',
                implode(' ', array_slice($options['positional'], 1)),
            ));
        }

        return $options;
    }

    /**
     * @param array{positional: list<string>, path: ?string, ui: ?string, no_install: bool, no_dev: bool, no_interaction: bool, interactive: bool, help: bool} $options
     */
    private function projectName(array $options, ConsoleIO $io): string
    {
        $name = $options['positional'][0] ?? null;

        if ($name !== null) {
            return trim($name);
        }

        if ($this->canPrompt($options, $io)) {
            $answer = $io->readLine('Project name: ');

            if ($answer === null) {
                throw new InstallerException('No project name was provided.');
            }

            $answer = trim($answer);

            if ($answer === '') {
                throw new InstallerException('Project name cannot be empty.');
            }

            return $answer;
        }

        if ($options['path'] !== null) {
            $derived = basename(rtrim($options['path'], '/\\'));

            if ($derived !== '' && $derived !== '.' && $derived !== '..') {
                return $derived;
            }
        }

        throw new InstallerException(
            'Project name is required when the terminal is non-interactive. '
            .'Pass a name (for example: bin/sahil new my-app --ui=tailwind) or use --path with a directory name.',
        );
    }

    private function selectedUi(?string $explicit, bool $noInteraction, ConsoleIO $io): Profile
    {
        if ($explicit !== null) {
            return $this->resolveUi($explicit);
        }

        if ($noInteraction || !$io->isInteractive()) {
            throw new InstallerException(
                "No UI framework was selected and the terminal is non-interactive.\n"
                .'Pass --ui=tailwind or --ui=bootstrap (the installer never installs both by default).',
            );
        }

        $io->line('Choose your UI framework:');
        $io->line('  [1] Tailwind CSS');
        $io->line('  [2] Bootstrap 5');
        $io->line();

        while (true) {
            $answer = $io->readLine('Select UI framework [1-2]: ');

            if ($answer === null) {
                throw new InstallerException(
                    'No UI framework was selected. Pass --ui=tailwind or --ui=bootstrap.',
                );
            }

            $answer = strtolower(trim($answer));

            if ($answer === '1' || $answer === 'tailwind' || $answer === 'tailwindcss') {
                return Profile::Tailwind;
            }

            if ($answer === '2' || $answer === 'bootstrap' || $answer === 'bootstrap5') {
                return Profile::Bootstrap;
            }

            $io->error(sprintf('Invalid choice [%s]. Enter 1 for Tailwind CSS or 2 for Bootstrap 5.', $answer));
        }
    }

    private function resolveUi(string $name): Profile
    {
        $profile = Profile::resolve($name);

        if ($profile !== Profile::Tailwind && $profile !== Profile::Bootstrap) {
            throw new InstallerException(sprintf(
                "Unknown UI framework [%s].\nAvailable frameworks:\n"
                ."  tailwind   Tailwind CSS v4\n"
                .'  bootstrap  Bootstrap 5',
                $name,
            ));
        }

        return $profile;
    }

    /**
     * @param array{positional: list<string>, path: ?string, ui: ?string, no_install: bool, no_dev: bool, no_interaction: bool, interactive: bool, help: bool} $options
     */
    private function canPrompt(array $options, ConsoleIO $io): bool
    {
        return !$options['no_interaction'] && $io->isInteractive();
    }

    private function absolutePath(string $path, string $workingDirectory): string
    {
        if (str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1) {
            return $path;
        }

        return rtrim($workingDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$path;
    }

    private function installer(): ProjectInstaller
    {
        return $this->installer ?? new ProjectInstaller();
    }

    private function printHelp(ConsoleIO $io): void
    {
        $io->line(<<<'TEXT'
            SahilPHP Installer

            Usage: bin/sahil new [name] [options]

            Create a new application from the SahilPHP starter-kit. The installer
            copies only application source files, then installs exactly one UI
            framework (Tailwind CSS or Bootstrap 5).

            Options:
              --path=DIR            Exact destination directory (default: ./<name>)
              --ui=NAME             UI framework: tailwind or bootstrap
                                    (also: --profile=NAME)
              --no-interaction, -n  Never prompt; requires a name and --ui
              --interactive         Force prompts even when STDIN is not a TTY
              --no-install          Generate files without Composer/npm installation
              --no-dev              Composer install without development dependencies
              -h, --help            Show this help text

            Examples:
              bin/sahil new my-app
              bin/sahil new my-app --path=/tmp/my-app --ui=tailwind
              bin/sahil new my-app --ui=bootstrap --no-install --no-interaction
            TEXT);
    }
}
