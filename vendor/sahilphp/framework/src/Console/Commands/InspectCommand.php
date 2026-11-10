<?php

declare(strict_types=1);

namespace Sahil\Console\Commands;

use InvalidArgumentException;
use Sahil\Console\Command;
use Sahil\Console\CommandInput;
use Sahil\Console\CommandOutput;
use Sahil\Console\ConsoleException;
use Sahil\Support\Inspection\Inspector;

/**
 * `about`, `config:show` and `env:check`: secret-safe application inspection.
 */
final class InspectCommand extends Command
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
            'about' => 'Show application facts (name, environment, debug, PHP).',
            'config:show' => 'Inspect configuration; secret values are always [redacted].',
            'env:check' => 'Check documented environment variables (names only).',
            default => 'Inspect the application.',
        };
    }

    public function usage(): string
    {
        return match ($this->command) {
            'config:show' => 'php sahil config:show [namespace]',
            'env:check' => 'php sahil env:check',
            default => 'php sahil about',
        };
    }

    public function category(): string
    {
        return 'Application';
    }

    /**
     * @return list<string>
     */
    public function examples(): array
    {
        return match ($this->command) {
            'config:show' => [
                'php sahil config:show',
                'php sahil config:show database',
            ],
            'env:check' => ['php sahil env:check'],
            default => ['php sahil about'],
        };
    }

    public function execute(CommandInput $input, CommandOutput $output): int
    {
        if ($this->command === 'about') {
            $input->rejectExtraArguments(0, $this->usage());

            foreach (Inspector::about($this->app()) as $label => $value) {
                $output->line(sprintf('%-13s %s', $label.':', $value));
            }

            return 0;
        }

        if ($this->command === 'config:show') {
            $input->rejectExtraArguments(1, $this->usage());
            $namespace = $input->argument(0);

            try {
                $flat = Inspector::configSafe($this->app(), $namespace);
            } catch (InvalidArgumentException $error) {
                throw new ConsoleException($error->getMessage());
            }

            foreach ($flat as $key => $value) {
                $display = match (true) {
                    is_bool($value) => $value ? 'true' : 'false',
                    $value === null => '(null)',
                    default => (string) $value,
                };

                $output->line(sprintf('%s = %s', $key, $display));
            }

            return 0;
        }

        if ($this->command === 'env:check') {
            $input->rejectExtraArguments(0, $this->usage());
            $check = Inspector::environmentCheck($this->app());

            foreach ($check['report'] as $line) {
                if ($check['exit'] === 0) {
                    $output->line($line);
                } else {
                    $output->error($line);
                }
            }

            return $check['exit'];
        }

        throw new ConsoleException(sprintf('Unknown inspection command [%s].', $this->command));
    }
}
