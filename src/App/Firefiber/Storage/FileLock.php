<?php

declare(strict_types=1);

namespace Sahil\Firefiber\Storage;

use RuntimeException;

/**
 * Process-independent advisory lock for one component's state record.
 *
 * The lock is held only while an action hydrates, executes, and persists
 * state. A long-running worker can retry or time out at a higher level;
 * this lock deliberately blocks briefly so two calls for one component
 * cannot interleave their authoritative reads and writes.
 */
final class FileLock
{
    /** @var resource|null */
    private $handle = null;

    private string $path;

    public function __construct(
        private readonly string $directory,
        private readonly string $name,
    ) {
        if ($name === '') {
            throw new RuntimeException('A Firefiber lock name is required.');
        }
    }

    public function acquire(): static
    {
        if ($this->handle !== null) {
            return $this;
        }

        if (!is_dir($this->directory) && !mkdir($this->directory, 0o775, true) && !is_dir($this->directory)) {
            throw new RuntimeException(sprintf('Unable to create Firefiber lock directory [%s].', $this->directory));
        }

        $this->path = rtrim($this->directory, '/').'/'.hash('sha256', $this->name).'.lock';
        $handle = @fopen($this->path, 'c+');

        if ($handle === false) {
            throw new RuntimeException(sprintf('Unable to open Firefiber lock [%s].', $this->path));
        }

        $this->handle = $handle;

        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            $this->handle = null;
            throw new RuntimeException(sprintf('Unable to acquire Firefiber lock [%s].', $this->path));
        }

        return $this;
    }

    public function release(): void
    {
        if ($this->handle === null || !is_resource($this->handle)) {
            return;
        }

        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }

    public function __destruct()
    {
        $this->release();
    }
}
