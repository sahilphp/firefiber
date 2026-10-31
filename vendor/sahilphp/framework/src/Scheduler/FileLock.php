<?php

declare(strict_types=1);

namespace Sahil\Scheduler;

use Sahil\Contracts\Scheduler\LockContract;

/**
 * Overlap prevention on one host, using `flock()`.
 *
 * One lock file per task name (the name is hashed, so it can never become a
 * path). The lock is held by the open file handle, which means:
 *  - a second process gets `false` immediately instead of waiting;
 *  - a crashed process releases the lock when the kernel closes its
 *    descriptor — no stale lock to clean up;
 *  - locking the same name twice through one instance also fails, so a
 *    single run cannot re-enter a task through the same lock object.
 *
 * This does NOT coordinate several hosts; use {@see DatabaseLock} when the
 * scheduler runs on more than one machine.
 */
final class FileLock implements LockContract
{
    /** @var array<string, resource> */
    private array $handles = [];

    private readonly string $directory;

    public function __construct(string $directory)
    {
        if ($directory === '') {
            throw new SchedulerException('The lock directory must not be empty.');
        }

        if (!is_dir($directory) && !@mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new SchedulerException(sprintf('Unable to create the lock directory [%s].', $directory));
        }

        $this->directory = rtrim($directory, '/');

        if (!is_writable($this->directory)) {
            throw new SchedulerException(sprintf('The lock directory [%s] is not writable.', $directory));
        }
    }

    public function acquire(string $name): bool
    {
        if (isset($this->handles[$name])) {
            return false;
        }

        $handle = @fopen($this->path($name), 'c');

        if ($handle === false) {
            throw new SchedulerException(sprintf('Unable to open the lock file for [%s].', $name));
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }

        // Best effort: record the holder so an operator can see who is
        // running. The lock itself is the flock, not this text.
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, (string) getmypid());
        fflush($handle);

        $this->handles[$name] = $handle;

        return true;
    }

    public function release(string $name): void
    {
        $handle = $this->handles[$name] ?? null;

        if ($handle === null) {
            return;
        }

        flock($handle, LOCK_UN);
        fclose($handle);

        unset($this->handles[$name]);
    }

    public function holds(string $name): bool
    {
        return isset($this->handles[$name]);
    }

    /**
     * Release everything still held when the object goes away.
     */
    public function __destruct()
    {
        foreach (array_keys($this->handles) as $name) {
            $this->release($name);
        }
    }

    private function path(string $name): string
    {
        return $this->directory.'/'.hash('sha256', $name).'.lock';
    }
}
