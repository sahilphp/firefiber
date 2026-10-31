<?php

declare(strict_types=1);

namespace Sahil\Testing;

use PHPUnit\Framework\Assert;

/**
 * The captured result of one console command run.
 *
 * Console commands write to STDOUT and STDERR; a test usually cares about both
 * and about the exit status. This object keeps them separate but exposes the
 * combined text for convenience. Failures show the command line, exit code and
 * the captured streams, so a failing command is readable without rerunning it
 * by hand.
 */
final class TestConsoleResult
{
    public function __construct(
        public readonly string $command,
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
    ) {
    }

    public function output(): string
    {
        return $this->stdout.$this->stderr;
    }

    public function assertSuccessful(): self
    {
        return $this->assertExitCode(0);
    }

    public function assertFailed(): self
    {
        Assert::assertNotSame(0, $this->exitCode, sprintf(
            "Expected command [%s] to fail, but it exited with 0.\n%s",
            $this->command,
            $this->diagnostics(),
        ));

        return $this;
    }

    public function assertExitCode(int $expected): self
    {
        Assert::assertSame($expected, $this->exitCode, sprintf(
            "Expected command [%s] to exit with %d, got %d.\n%s",
            $this->command,
            $expected,
            $this->exitCode,
            $this->diagnostics(),
        ));

        return $this;
    }

    public function assertOutputContains(string $needle): self
    {
        Assert::assertTrue(str_contains($this->output(), $needle), sprintf(
            "Expected output of [%s] to contain [%s].\n%s",
            $this->command,
            $needle,
            $this->diagnostics(),
        ));

        return $this;
    }

    public function assertOutputMissing(string $needle): self
    {
        Assert::assertFalse(str_contains($this->output(), $needle), sprintf(
            "Expected output of [%s] not to contain [%s].\n%s",
            $this->command,
            $needle,
            $this->diagnostics(),
        ));

        return $this;
    }

    public function assertOutputMatches(string $pattern): self
    {
        Assert::assertMatchesRegularExpression($pattern, $this->output(), sprintf(
            "Expected output of [%s] to match [%s].\n%s",
            $this->command,
            $pattern,
            $this->diagnostics(),
        ));

        return $this;
    }

    /**
     * Captured stdout/stderr, trimmed and truncated for failure reports.
     */
    public function diagnostics(int $limit = 2000): string
    {
        return sprintf(
            "Exit code: %d\nSTDOUT:\n%s\nSTDERR:\n%s",
            $this->exitCode,
            self::excerpt($this->stdout, $limit),
            self::excerpt($this->stderr, $limit),
        );
    }

    private static function excerpt(string $value, int $limit): string
    {
        $value = trim($value);

        if ($value === '') {
            return '(empty)';
        }

        if (strlen($value) <= $limit) {
            return $value;
        }

        return substr($value, 0, $limit).sprintf("\n… (%d more bytes)", strlen($value) - $limit);
    }
}
