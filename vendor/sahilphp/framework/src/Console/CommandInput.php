<?php

declare(strict_types=1);

namespace Sahil\Console;

/**
 * Parsed command arguments and options.
 *
 * Parsing is deliberately strict: an option the command did not declare is an
 * error, and an option that needs a value fails when the value is missing.
 * That keeps typos (`--fourch`) from silently doing nothing.
 */
final class CommandInput
{
    /**
     * @param list<string> $arguments
     * @param array<string, string|null> $options
     * @param list<string> $tokens
     */
    private function __construct(
        private readonly array $arguments,
        private readonly array $options,
        private readonly array $tokens = [],
    ) {
    }

    /**
     * @param list<string> $tokens arguments after the command name
     * @param array<string, string|null> $specs option name => value placeholder, null for flags
     */
    public static function parse(array $tokens, array $specs): self
    {
        $arguments = [];
        $options = [];

        for ($index = 0; $index < count($tokens); $index++) {
            $token = (string) $tokens[$index];

            // `-n` is the conventional short form of --no-interaction.
            if ($token === '-n') {
                $token = '--no-interaction';
            }

            if (!str_starts_with($token, '--')) {
                if ($token !== '') {
                    $arguments[] = $token;
                }

                continue;
            }

            $name = substr($token, 2);
            $value = null;

            if (str_contains($name, '=')) {
                [$name, $value] = explode('=', $name, 2);
            }

            if (!array_key_exists($name, $specs)) {
                throw new ConsoleException(sprintf('unknown option [--%s].', $name));
            }

            if ($specs[$name] === null) {
                if ($value !== null) {
                    throw new ConsoleException(sprintf('--%s does not take a value.', $name));
                }

                $options[$name] = '1';

                continue;
            }

            if ($value === null) {
                $next = $tokens[$index + 1] ?? null;

                if (!is_string($next) || $next === '' || str_starts_with($next, '--')) {
                    throw new ConsoleException(sprintf('--%s requires a value.', $name));
                }

                $value = $next;
                $index++;
            }

            $value = trim($value);

            if ($value === '') {
                throw new ConsoleException(sprintf('--%s requires a non-empty value.', $name));
            }

            $options[$name] = $value;
        }

        return new self($arguments, $options, array_values($tokens));
    }

    /**
     * Original tokens after the command name. Used by commands that delegate
     * to an existing parser (the installer, UI tooling).
     *
     * @return list<string>
     */
    public function tokens(): array
    {
        return $this->tokens;
    }

    /**
     * @return list<string>
     */
    public function arguments(): array
    {
        return $this->arguments;
    }

    public function argument(int $index): ?string
    {
        return $this->arguments[$index] ?? null;
    }

    public function option(string $name): ?string
    {
        return $this->options[$name] ?? null;
    }

    public function flag(string $name): bool
    {
        return array_key_exists($name, $this->options);
    }

    /**
     * Require exactly one positional argument with a helpful message.
     */
    public function requireArgument(int $index, string $label): string
    {
        $value = $this->argument($index);

        if ($value === null || trim($value) === '') {
            throw new ConsoleException(sprintf('%s is required.', $label));
        }

        return $value;
    }

    public function rejectExtraArguments(int $allowed, string $usage): void
    {
        if (count($this->arguments) > $allowed) {
            throw new ConsoleException(sprintf(
                'unexpected argument [%s]. usage: %s',
                implode(' ', array_slice($this->arguments, $allowed)),
                $usage,
            ));
        }
    }
}
