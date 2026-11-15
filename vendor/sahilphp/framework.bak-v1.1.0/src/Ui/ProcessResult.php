<?php

declare(strict_types=1);

namespace Sahil\Ui;

/**
 * Captured result of an external process started without a shell.
 */
final class ProcessResult
{
    /**
     * @param list<string> $command
     */
    public function __construct(
        public readonly array $command,
        public readonly int $exitCode,
        public readonly string $stdout = '',
        public readonly string $stderr = '',
    ) {
    }

    public function successful(): bool
    {
        return $this->exitCode === 0;
    }

    /**
     * Shell-safe rendering for terminal output (never used to execute).
     */
    public function describe(): string
    {
        return implode(' ', array_map(
            static fn (string $token): string => preg_match('/^[A-Za-z0-9_@%+=:,.\/-]+$/', $token) === 1
                ? $token
                : escapeshellarg($token),
            $this->command,
        ));
    }
}
