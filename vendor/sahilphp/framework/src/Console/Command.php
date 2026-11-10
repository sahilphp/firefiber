<?php

declare(strict_types=1);

namespace Sahil\Console;

use Sahil\Contracts\Foundation\ApplicationContract;

/**
 * One console command.
 *
 * Built-in commands are registered by {@see Application}; an application can
 * register its own by listing class names in `bootstrap/commands.php`. The
 * command receives the booted application only when it declares
 * {@see requiresApplication()} — file generators, setup commands and asset
 * commands therefore work in a project that cannot boot yet.
 *
 * ```php
 * final class SendInvoice extends Command
 * {
 *     public function name(): string { return 'app:send-invoice'; }
 *     public function description(): string { return 'Send a test invoice.'; }
 *     public function execute(CommandInput $input, CommandOutput $output): int
 *     {
 *         $output->line('sent');
 *         return 0;
 *     }
 * }
 * ```
 */
abstract class Command
{
    private ?ApplicationContract $application = null;

    private string $basePath = '';

    /**
     * The command name as typed on the terminal, e.g. `unda:controller`.
     */
    abstract public function name(): string;

    /**
     * One-line description shown in the command list.
     */
    abstract public function description(): string;

    abstract public function execute(CommandInput $input, CommandOutput $output): int;

    /**
     * Help category, in the order commands are grouped.
     */
    public function category(): string
    {
        return 'Application';
    }

    /**
     * Public invocation shown in help. Never includes a file path.
     */
    public function usage(): string
    {
        return 'php sahil '.$this->name().' [options]';
    }

    /**
     * Option specification: name => value placeholder, or null for a flag.
     *
     * `--help`/`-h` is handled by the dispatcher and must not be listed.
     *
     * @return array<string, string|null>
     */
    public function optionSpecs(): array
    {
        return [];
    }

    /**
     * Extra help lines for options that need more than a placeholder.
     *
     * @return list<string>
     */
    public function optionHelp(): array
    {
        return [];
    }

    /**
     * @return list<string>
     */
    public function examples(): array
    {
        return [];
    }

    /**
     * Whether the application must boot before the command runs.
     */
    public function requiresApplication(): bool
    {
        return true;
    }

    /**
     * Hidden commands stay callable but are not listed in help.
     */
    public function hidden(): bool
    {
        return false;
    }

    final public function bindApplication(ApplicationContract $application): void
    {
        $this->application = $application;
    }

    final public function bindBasePath(string $basePath): void
    {
        $this->basePath = rtrim($basePath, '/');
    }

    /**
     * Application root as resolved by the entry point, even when the command
     * does not boot the application (generators, setup commands).
     */
    final protected function basePath(): string
    {
        if ($this->basePath === '') {
            throw new ConsoleException(sprintf('%s has no application root bound.', $this->name()));
        }

        return $this->basePath;
    }

    final protected function app(): ApplicationContract
    {
        if ($this->application === null) {
            throw new ConsoleException(sprintf(
                '%s requires a booted application, but none is available.',
                $this->name(),
            ));
        }

        return $this->application;
    }

    /**
     * Render command-specific help using only the public invocation name.
     */
    public function help(): string
    {
        $lines = [
            'SahilPHP Framework',
            '',
            'Usage:',
            '  '.$this->usage(),
            '',
            $this->description(),
        ];

        $options = ['  -h, --help'.str_repeat(' ', 14).'Show this command help.'];

        foreach ($this->optionSpecs() as $name => $value) {
            $options[] = sprintf(
                '  --%s%s%s',
                $name,
                $value === null ? '' : '='.$value,
                str_repeat(' ', max(1, 20 - strlen($name.($value === null ? '' : '='.$value)))),
            );
        }

        $lines[] = '';
        $lines[] = 'Options:';

        foreach ($this->optionHelp() as $line) {
            $lines[] = '  '.$line;
        }

        $lines[] = implode("\n", $options);

        if ($this->examples() !== []) {
            $lines[] = '';
            $lines[] = 'Examples:';

            foreach ($this->examples() as $example) {
                $lines[] = '  '.$example;
            }
        }

        return implode("\n", $lines);
    }
}
