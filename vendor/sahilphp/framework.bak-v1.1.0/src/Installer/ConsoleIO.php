<?php

declare(strict_types=1);

namespace Sahil\Installer;

/**
 * Small, testable terminal abstraction for the installer.
 *
 * Prompts are only attempted when the stream is a TTY (or the caller forces
 * interactive mode), so CI never blocks waiting for input.
 */
final class ConsoleIO
{
    /** @var resource */
    private $input;

    /** @var resource */
    private $output;

    /** @var resource */
    private $error;

    private bool $interactive;

    /**
     * @param resource $input
     * @param resource $output
     * @param resource $error
     */
    public function __construct($input, $output, $error, bool $interactive)
    {
        $this->input = $input;
        $this->output = $output;
        $this->error = $error;
        $this->interactive = $interactive;
    }

    public static function standard(): self
    {
        $interactive = defined('STDIN')
            && function_exists('stream_isatty')
            && @stream_isatty(STDIN);

        return new self(
            self::stream('STDIN', 'php://stdin', 'r'),
            self::stream('STDOUT', 'php://stdout', 'w'),
            self::stream('STDERR', 'php://stderr', 'w'),
            $interactive,
        );
    }

    /**
     * @return resource
     */
    private static function stream(string $constant, string $fallback, string $mode)
    {
        if (defined($constant)) {
            $stream = constant($constant);

            if (is_resource($stream)) {
                return $stream;
            }
        }

        $stream = fopen($fallback, $mode);

        if ($stream === false) {
            throw new InstallerException(sprintf('Unable to open [%s].', $fallback));
        }

        return $stream;
    }

    public function isInteractive(): bool
    {
        return $this->interactive;
    }

    public function forceInteractive(): void
    {
        $this->interactive = true;
    }

    public function readLine(string $prompt): ?string
    {
        fwrite($this->output, $prompt);
        $line = fgets($this->input);

        if ($line === false) {
            return null;
        }

        return rtrim($line, "\r\n");
    }

    public function line(string $line = ''): void
    {
        fwrite($this->output, $line.PHP_EOL);
    }

    public function error(string $line): void
    {
        fwrite($this->error, $line.PHP_EOL);
    }
}
