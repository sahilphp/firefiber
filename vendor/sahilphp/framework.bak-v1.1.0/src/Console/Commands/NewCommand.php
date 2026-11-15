<?php

declare(strict_types=1);

namespace Sahil\Console\Commands;

use Sahil\Console\Command;
use Sahil\Console\CommandInput;
use Sahil\Console\CommandOutput;
use Sahil\Installer\ProjectConsole;

/**
 * Creates a new application from this installation's starter-kit.
 */
final class NewCommand extends Command
{
    public function name(): string
    {
        return 'new';
    }

    public function description(): string
    {
        return 'Create a new SahilPHP application from this starter-kit.';
    }

    public function usage(): string
    {
        return 'php sahil new [name] [--path=DIR] [--ui=tailwind|bootstrap] [--no-install]';
    }

    public function category(): string
    {
        return 'Project';
    }

    public function optionSpecs(): array
    {
        return [
            'path' => 'DIR',
            'ui' => 'NAME',
            'profile' => 'NAME',
            'ui-framework' => 'NAME',
            'no-install' => null,
            'no-dev' => null,
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
    public function examples(): array
    {
        return [
            'php sahil new my-app',
            'php sahil new my-app --ui=tailwind',
            'php sahil new my-app --path=/tmp/my-app --ui=bootstrap --no-install',
        ];
    }

    public function execute(CommandInput $input, CommandOutput $output): int
    {
        $workingDirectory = getcwd();

        return (new ProjectConsole())->run(
            $input->tokens(),
            $this->basePath(),
            $workingDirectory === false ? $this->basePath() : $workingDirectory,
        );
    }
}
