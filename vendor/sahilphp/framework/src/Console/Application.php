<?php

declare(strict_types=1);

namespace Sahil\Console;

use Sahil\Contracts\Foundation\ApplicationContract;
use Throwable;

/**
 * The SahilPHP console: owns command registration, dispatch, help and error
 * reporting. The root `sahil` entry point only loads Composer and calls
 * {@see Application::run()}.
 *
 * Built-in commands are registered here, in the framework package, so a
 * generated application contains no copy of the framework CLI. Applications
 * extend the console deliberately with `bootstrap/commands.php`, a manifest
 * returning command class names.
 */
final class Application
{
    public const NAME = 'SahilPHP Framework';

    /** @var list<string> */
    private const CATEGORY_ORDER = [
        'Application',
        'Routing',
        'Database',
        'Queues',
        'Scheduler',
        'Code Generation',
        'Frontend',
        'Project',
    ];

    /** @var array<string, Command> */
    private array $commands = [];

    private ?ApplicationContract $application = null;

    private bool $applicationCommandsLoaded = false;

    public function __construct(
        private readonly string $basePath,
        private readonly ?CommandOutput $output = null,
    ) {
        $this->registerBuiltInCommands();
    }

    /**
     * @param list<string> $arguments arguments after the executable name
     */
    public function run(array $arguments): int
    {
        $output = $this->output ?? new CommandOutput();

        try {
            return $this->dispatch($arguments, $output);
        } catch (ConsoleException $error) {
            $output->error($error->getMessage());

            return 1;
        } catch (Throwable $error) {
            $message = $error->getMessage() === '' ? $error::class : $error->getMessage();
            $output->error($message);

            if (getenv('SAHIL_DEBUG') === '1') {
                $output->error($error::class);
            }

            return 1;
        }
    }

    /**
     * @param list<string> $arguments
     */
    private function dispatch(array $arguments, CommandOutput $output): int
    {
        if ($arguments === [] || $arguments[0] === '') {
            $output->line($this->help());

            return 0;
        }

        $first = (string) $arguments[0];

        if ($first === 'help') {
            $target = $arguments[1] ?? null;

            if ($target === null) {
                $output->line($this->help());

                return 0;
            }

            $output->line($this->command($target)->help());

            return 0;
        }

        if ($first === '--help' || $first === '-h') {
            $output->line($this->help());

            return 0;
        }

        if ($first === '--version' || $first === '-V') {
            $output->line($this->version());

            return 0;
        }

        if (str_starts_with($first, '-')) {
            $output->error(sprintf('Unknown option: %s', $first));
            $output->error($this->help());

            return 1;
        }

        $command = $this->find($first);

        if ($command === null) {
            // Application-specific commands live in a manifest; load it only
            // when the built-in registry cannot satisfy the request.
            $this->loadApplicationCommands();

            $command = $this->find($first);
        }

        if ($command === null) {
            $output->error(sprintf('Unknown command: %s', $first));
            $output->error($this->help());

            return 1;
        }

        $tokens = array_values(array_slice($arguments, 1));

        if (in_array('--help', $tokens, true) || in_array('-h', $tokens, true)) {
            $output->line($command->help());

            return 0;
        }

        if ($command->requiresApplication()) {
            $command->bindApplication($this->boot());
        }

        $input = CommandInput::parse($tokens, $command->optionSpecs());

        return $command->execute($input, $output);
    }

    private function find(string $name): ?Command
    {
        return $this->commands[$name] ?? null;
    }

    private function command(string $name): Command
    {
        $command = $this->find($name);

        if ($command === null) {
            $this->loadApplicationCommands();
            $command = $this->find($name);
        }

        if ($command === null) {
            throw new ConsoleException(sprintf('Unknown command: %s', $name));
        }

        return $command;
    }

    /**
     * Root help, generated from the registered command set. Nothing is
     * hardcoded: a command that is not registered never appears.
     */
    public function help(): string
    {
        $this->loadApplicationCommands();

        $grouped = [];

        foreach ($this->commands as $command) {
            if ($command->hidden()) {
                continue;
            }

            $grouped[$command->category()][] = $command;
        }

        $categories = array_values(array_unique([...self::CATEGORY_ORDER, ...array_keys($grouped)]));
        $lines = [
            self::NAME,
            '',
            'Usage:',
            '  php sahil <command> [arguments] [options]',
            '',
            'Available commands:',
        ];

        foreach ($categories as $category) {
            if (!isset($grouped[$category])) {
                continue;
            }

            $commands = $grouped[$category];
            usort($commands, static fn (Command $a, Command $b): int => strcmp($a->name(), $b->name()));

            $lines[] = '';
            $lines[] = '  '.$category;

            $width = max(array_map(static fn (Command $command): int => strlen($command->name()), $commands));

            foreach ($commands as $command) {
                $lines[] = sprintf(
                    '    %s  %s',
                    str_pad($command->name(), $width),
                    $command->description(),
                );
            }
        }

        $lines[] = '';
        $lines[] = 'Options:';
        $lines[] = '  -h, --help   Show this help, or the help of one command.';
        $lines[] = '  -V, --version  Print the framework and PHP versions.';
        $lines[] = '';
        $lines[] = 'Run `php sahil <command> --help` for command-specific options.';

        return implode("\n", $lines);
    }

    /**
     * @return array<string, Command>
     */
    public function commands(): array
    {
        return $this->commands;
    }

    public function register(Command $command): void
    {
        $command->bindBasePath($this->basePath);
        $this->commands[$command->name()] = $command;
    }

    private function version(): string
    {
        $name = self::NAME;

        try {
            $configured = $this->boot()->config()->get('app.name');

            if (is_string($configured) && trim($configured) !== '') {
                $name = $configured;
            }
        } catch (Throwable) {
            // A project that cannot boot still reports a usable version.
        }

        return sprintf('%s, PHP %s', $name, PHP_VERSION);
    }

    private function boot(): ApplicationContract
    {
        if ($this->application !== null) {
            return $this->application;
        }

        $bootstrap = rtrim($this->basePath, '/').'/bootstrap/app.php';

        if (!is_file($bootstrap)) {
            throw new ConsoleException(sprintf(
                'bootstrap/app.php is missing from [%s]. Run this command from an application root.',
                $this->basePath,
            ));
        }

        try {
            if (function_exists('sahil_bootstrap')) {
                \Sahil\Foundation\Application::free();
                $application = \sahil_bootstrap();
            } else {
                /** @var mixed $application */
                $application = require $bootstrap;
            }
        } catch (Throwable $error) {
            throw new ConsoleException(sprintf('Boot error: %s', $error->getMessage()));
        }

        if (!$application instanceof ApplicationContract) {
            throw new ConsoleException('bootstrap/app.php did not return a booted application.');
        }

        return $this->application = $application;
    }

    /**
     * Load `bootstrap/commands.php` when present. The manifest is an explicit
     * extension point: an application lists its command classes there instead
     * of copying framework command files.
     */
    private function loadApplicationCommands(): void
    {
        if ($this->applicationCommandsLoaded) {
            return;
        }

        $this->applicationCommandsLoaded = true;
        $manifest = rtrim($this->basePath, '/').'/bootstrap/commands.php';

        if (!is_file($manifest)) {
            return;
        }

        /** @var mixed $classes */
        $classes = require $manifest;

        if (!is_array($classes)) {
            throw new ConsoleException('bootstrap/commands.php must return a list of command class names.');
        }

        if ($classes === []) {
            return;
        }

        $application = $this->boot();

        foreach ($classes as $class) {
            if (!is_string($class) || !class_exists($class)) {
                throw new ConsoleException(sprintf(
                    'bootstrap/commands.php entry [%s] is not an existing command class.',
                    is_string($class) ? $class : get_debug_type($class),
                ));
            }

            $command = new $class();
            $command->bindApplication($application);

            if (!$command instanceof Command) {
                throw new ConsoleException(sprintf('%s must extend %s.', $class, Command::class));
            }

            $this->register($command);
        }
    }

    private function registerBuiltInCommands(): void
    {
        foreach (['about', 'config:show', 'env:check'] as $name) {
            $this->register(new Commands\InspectCommand($name));
        }

        $this->register(new Commands\RouteListCommand());
        $this->register(new Commands\MigrationCommand('migrate'));
        $this->register(new Commands\MigrationCommand('migrate:status'));
        $this->register(new Commands\MigrationCommand('migrate:rollback'));
        $this->register(new Commands\MigrationCommand('db:seed'));

        foreach (['queue:work', 'queue:failed', 'queue:retry', 'queue:flush'] as $name) {
            $this->register(new Commands\QueueCommand($name));
        }

        $this->register(new Commands\ScheduleCommand('schedule:list'));
        $this->register(new Commands\ScheduleCommand('schedule:run'));

        $this->register(new Commands\ProjectSetupCommand('key:generate'));
        $this->register(new Commands\ProjectSetupCommand('storage:prepare'));
        $this->register(new Commands\StartCommand('start'));
        $this->register(new Commands\StartCommand('serve', isHidden: true));
        $this->register(new Commands\NewCommand());

        $this->register(new Commands\UiSetupCommand());

        foreach (['ui:install', 'ui:dev', 'ui:build', 'ui:status', 'ui:theme'] as $name) {
            $this->register(new Commands\UiCommand($name));
        }

        $this->register(new Commands\AuthCommand('unda:auth'));
        // Deliberate, documented compatibility alias: `make:auth` keeps
        // working, but help and documentation present `unda:auth`.
        $this->register(new Commands\AuthCommand('make:auth', isHidden: true));

        foreach ([
            'controller',
            'model',
            'migration',
            'middleware',
            'policy',
            'provider',
            'command',
            'seeder',
        ] as $type) {
            $this->register(new Commands\GeneratorCommand($type));
        }
    }
}
