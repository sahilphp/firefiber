<?php

declare(strict_types=1);

namespace Sahil\Ui;

/**
 * Starts external programs without a shell.
 *
 * The command is always an argument vector passed to `proc_open()` as an
 * array. There is no string concatenation and no shell, so untrusted values
 * (paths, profile names, extra Vite flags) can never become shell syntax.
 */
final class ProcessRunner
{
    public function __construct(
        private readonly int $pollMicroseconds = 10_000,
    ) {
    }

    /**
     * Run a command and capture its output.
     *
     * @param list<string>          $command
     * @param array<string, string> $environment extra variables (the current
     *                                            environment is inherited)
     */
    public function run(array $command, string $cwd, array $environment = []): ProcessResult
    {
        $this->validate($command);

        $stdoutFile = $this->temporaryFile('stdout');
        $stderrFile = $this->temporaryFile('stderr');

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $stdoutFile, 'w'],
            2 => ['file', $stderrFile, 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, $cwd, $this->environment($environment));

        if (!is_resource($process)) {
            @unlink($stdoutFile);
            @unlink($stderrFile);

            throw new UiException(sprintf(
                'Unable to start [%s]. Check that the executable exists and is runnable.',
                implode(' ', $command),
            ));
        }

        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }

        $this->wait($process);
        $exitCode = proc_close($process);

        $stdout = (string) file_get_contents($stdoutFile);
        $stderr = (string) file_get_contents($stderrFile);

        @unlink($stdoutFile);
        @unlink($stderrFile);

        return new ProcessResult($command, $exitCode, $stdout, $stderr);
    }

    /**
     * Run a long-lived command attached to the terminal (`ui:dev`).
     *
     * @param list<string> $command
     */
    public function runInteractive(array $command, string $cwd): ProcessResult
    {
        $this->validate($command);

        $descriptors = [
            0 => STDIN,
            1 => STDOUT,
            2 => STDERR,
        ];

        $process = proc_open($command, $descriptors, $pipes, $cwd);

        if (!is_resource($process)) {
            throw new UiException(sprintf(
                'Unable to start [%s]. Check that the executable exists and is runnable.',
                implode(' ', $command),
            ));
        }

        return new ProcessResult($command, proc_close($process));
    }

    /**
     * Wait for the process to exit without blocking output collection.
     *
     * @param resource $process
     */
    private function wait(mixed $process): void
    {
        while (true) {
            $status = proc_get_status($process);

            if ($status['running'] === false) {
                return;
            }

            usleep($this->pollMicroseconds);
        }
    }

    /**
     * @param array<int, mixed> $command
     */
    private function validate(array $command): void
    {
        if ($command === []) {
            throw new UiException('An external command cannot be empty.');
        }

        foreach ($command as $token) {
            if (!is_string($token) || $token === '' || str_contains($token, "\0")) {
                throw new UiException('External command arguments must be non-empty strings without NUL bytes.');
            }
        }
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private function environment(array $overrides): array
    {
        /** @var array<string, string> $inherited */
        $inherited = [];

        foreach (getenv() as $key => $value) {
            $inherited[$key] = $value;
        }

        return [...$inherited, ...$overrides];
    }

    private function temporaryFile(string $name): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sahil-ui-'.$name.'-');

        if ($path === false) {
            throw new UiException(sprintf('Unable to create a temporary %s file.', $name));
        }

        return $path;
    }
}
