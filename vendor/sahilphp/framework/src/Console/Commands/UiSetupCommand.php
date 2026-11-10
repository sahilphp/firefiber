<?php

declare(strict_types=1);

namespace Sahil\Console\Commands;

use Sahil\Console\Command;
use Sahil\Console\CommandInput;
use Sahil\Console\CommandOutput;
use Sahil\Console\ConsoleException;

/**
 * Copies exactly one UI kit and one CSS framework from the starter-kit stubs.
 *
 * The setup helper is deliberately dependency-free so the installer can run
 * this command before Composer installs the framework.
 */
final class UiSetupCommand extends Command
{
    public function name(): string
    {
        return 'ui:setup';
    }

    public function description(): string
    {
        return 'Install one UI kit (Firefiber, React, Vue, Svelte or none) and one CSS framework.';
    }

    public function usage(): string
    {
        return 'php sahil ui:setup [--ui-kit=firefiber|react|vue|svelte|none] [--css=tailwind|bootstrap]';
    }

    public function category(): string
    {
        return 'Frontend';
    }

    public function optionSpecs(): array
    {
        return [
            'ui-kit' => 'KIT',
            'css' => 'NAME',
            'path' => 'DIR',
            'no-interaction' => null,
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
            'php sahil ui:setup',
            'php sahil ui:setup --ui-kit=react --css=tailwind',
            'php sahil ui:setup --ui-kit=none --css=bootstrap --no-interaction',
        ];
    }

    public function execute(CommandInput $input, CommandOutput $output): int
    {
        $class = class_exists('App\\Support\\UiSetup')
            ? 'App\\Support\\UiSetup'
            : (class_exists('Sahil\\Ui\\UiSetup') ? 'Sahil\\Ui\\UiSetup' : null);

        if ($class === null) {
            throw new ConsoleException(
                'the UI setup helper is missing from this installation (expected app/Support/UiSetup.php).',
            );
        }

        /** @var object $setup */
        $setup = new $class();

        if (!method_exists($setup, 'run')) {
            throw new ConsoleException(sprintf('%s does not expose run().', $class));
        }

        return (int) $setup->run($input->tokens(), $this->basePath());
    }
}
