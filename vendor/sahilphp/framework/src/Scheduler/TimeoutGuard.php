<?php

declare(strict_types=1);

namespace Sahil\Scheduler;

use Closure;

/**
 * Runs a task under a deadline.
 *
 * When the process can receive signals (CLI with pcntl), the task is really
 * interrupted: SIGALRM fires, a {@see TaskTimeoutException} is thrown from
 * the handler and unwinds the call stack. Elsewhere (for example a web
 * process without pcntl) PHP cannot interrupt synchronous code, so the
 * guard measures the elapsed time and raises the same exception AFTER the
 * task returns — the run is still reported as timed out, but it was not
 * stopped mid-flight. `supportsInterruption()` exposes which mode applies.
 */
final class TimeoutGuard
{
    private readonly bool $interrupts;

    public function __construct(?bool $interrupts = null)
    {
        $this->interrupts = $interrupts ?? (
            PHP_SAPI === 'cli'
            && function_exists('pcntl_alarm')
            && function_exists('pcntl_signal')
            && function_exists('pcntl_async_signals')
        );
    }

    public function supportsInterruption(): bool
    {
        return $this->interrupts;
    }

    /**
     * @param Closure(): void $work
     *
     * @throws TaskTimeoutException when the deadline is exceeded
     */
    public function run(string $task, Closure $work, int $seconds): void
    {
        if ($seconds <= 0) {
            $work();

            return;
        }

        if (!$this->interrupts) {
            $started = microtime(true);

            $work();

            if (microtime(true) - $started > $seconds) {
                throw TaskTimeoutException::after($task, $seconds);
            }

            return;
        }

        $previous = pcntl_signal_get_handler(SIGALRM);

        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, static function () use ($task, $seconds): void {
            throw TaskTimeoutException::after($task, $seconds);
        });
        pcntl_alarm($seconds);

        try {
            $work();
        } finally {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, is_callable($previous) ? $previous : SIG_DFL);
        }
    }
}
