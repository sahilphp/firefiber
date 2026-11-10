<?php

declare(strict_types=1);

namespace Sahil\Console;

/**
 * Console output abstraction.
 *
 * Commands never write to STDOUT/STDERR directly, so their output is
 * assertable in-process (tests can pass php://memory streams).
 */
class CommandOutput
{
    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    /**
     * @param resource|null $stdout
     * @param resource|null $stderr
     */
    public function __construct($stdout = null, $stderr = null)
    {
        $this->stdout = $stdout ?? self::stream('STDOUT', 'php://stdout', 'w');
        $this->stderr = $stderr ?? self::stream('STDERR', 'php://stderr', 'w');
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
            throw new ConsoleException(sprintf('Unable to open [%s].', $fallback));
        }

        return $stream;
    }

    public function line(string $line = ''): void
    {
        fwrite($this->stdout, $line.PHP_EOL);
    }

    public function error(string $line): void
    {
        fwrite($this->stderr, $line.PHP_EOL);
    }

    public function write(string $text): void
    {
        fwrite($this->stdout, $text);
    }

    /**
     * @return resource
     */
    public function stdout()
    {
        return $this->stdout;
    }

    /**
     * @return resource
     */
    public function stderr()
    {
        return $this->stderr;
    }
}
