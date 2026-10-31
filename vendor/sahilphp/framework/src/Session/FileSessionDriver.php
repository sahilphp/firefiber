<?php

declare(strict_types=1);

namespace Sahil\Session;

use RuntimeException;
use Sahil\Contracts\Session\SessionDriverContract;
use Throwable;

/**
 * File-based session storage driver.
 *
 * Stores session data as serialized PHP arrays in individual files.
 * Uses exclusive file locking to prevent race conditions in concurrent
 * requests.
 *
 * Security features:
 * - Session IDs are 40-character hex strings (SHA1 of random bytes)
 * - File permissions are restricted to owner-only (0600)
 * - Exclusive locking prevents data corruption
 * - Garbage collection removes expired sessions
 */
final class FileSessionDriver implements SessionDriverContract
{
    private const ID_LENGTH = 40;

    private const ID_PATTERN = '/^[a-f0-9]{40}$/';

    public function __construct(
        private readonly string $path,
    ) {
        $this->ensureDirectoryExists();
    }

    public function read(string $id): array
    {
        $this->validateId($id);

        $file = $this->filePath($id);

        if (!is_file($file)) {
            return [];
        }

        $handle = @fopen($file, 'rb');

        if ($handle === false) {
            return [];
        }

        if (!flock($handle, LOCK_SH)) {
            fclose($handle);

            return [];
        }

        try {
            $content = stream_get_contents($handle);

            if ($content === false) {
                return [];
            }

            $data = $this->unserialize($content);

            return is_array($data) ? $data : [];
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function write(string $id, array $data): void
    {
        $this->validateId($id);

        $file = $this->filePath($id);

        $handle = @fopen($file, 'cb');

        if ($handle === false) {
            throw new RuntimeException(
                sprintf('Failed to open session file for writing: %s', $file),
            );
        }

        if (!flock($handle, LOCK_EX)) {
            fclose($handle);

            throw new RuntimeException(
                sprintf('Failed to acquire exclusive lock on session file: %s', $file),
            );
        }

        try {
            ftruncate($handle, 0);
            rewind($handle);

            $serialized = $this->serialize($data);

            if (fwrite($handle, $serialized) === false) {
                throw new RuntimeException(
                    sprintf('Failed to write session data to file: %s', $file),
                );
            }

            // Ensure the file is readable/writable only by the owner
            chmod($file, 0o600);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function destroy(string $id): void
    {
        $this->validateId($id);

        $file = $this->filePath($id);

        if (is_file($file)) {
            @unlink($file);
        }
    }

    public function gc(int $lifetime): void
    {
        $cutoff = time() - $lifetime;

        foreach (glob($this->path.'/*') as $file) {
            if (!is_file($file)) {
                continue;
            }

            if (filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }

    public function generateId(): string
    {
        // Generate random bytes and encode as hex: ID_LENGTH characters.
        $bytes = random_bytes(intdiv(self::ID_LENGTH, 2));

        return bin2hex($bytes);
    }

    public function isValidId(string $id): bool
    {
        return preg_match(self::ID_PATTERN, $id) === 1;
    }

    /**
     * Get the full file path for a session ID.
     */
    private function filePath(string $id): string
    {
        return $this->path.'/'.$id;
    }

    /**
     * Validate that a session ID is in the correct format.
     */
    private function validateId(string $id): void
    {
        if (!$this->isValidId($id)) {
            throw new RuntimeException(
                sprintf('Invalid session ID format: %s', $id),
            );
        }
    }

    /**
     * Serialize session data safely.
     *
     * Uses JSON encoding to prevent object injection attacks.
     * Only JSON-encodable data types are supported.
     *
     * @param array<string, mixed> $data
     */
    private function serialize(array $data): string
    {
        try {
            return json_encode($data, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new RuntimeException(
                sprintf('Session data serialization failed: %s', $e->getMessage()),
                0,
                $e,
            );
        }
    }

    /**
     * Unserialize session data safely.
     *
     * Uses JSON decoding to prevent object injection attacks.
     */
    private function unserialize(string $data): mixed
    {
        try {
            return json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            // Return empty array on decode failure (corrupted session)
            return [];
        }
    }

    /**
     * Ensure the session directory exists and is writable.
     */
    private function ensureDirectoryExists(): void
    {
        if (!is_dir($this->path)) {
            if (!@mkdir($this->path, 0o700, true)) {
                throw new RuntimeException(
                    sprintf('Failed to create session directory: %s', $this->path),
                );
            }
        }

        if (!is_writable($this->path)) {
            throw new RuntimeException(
                sprintf('Session directory is not writable: %s', $this->path),
            );
        }
    }
}
