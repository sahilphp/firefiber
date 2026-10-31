<?php

declare(strict_types=1);

namespace Sahil\Storage;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Sahil\Contracts\Storage\StorageDriverContract;

/**
 * Local filesystem storage driver.
 *
 * Every path is relative to a configured root. The driver is designed so
 * that nothing outside that root can ever be read, written, or listed:
 *
 * - absolute paths, `.` and `..` segments, and NUL bytes are rejected;
 * - symbolic links are never followed (they are rejected outright);
 * - every resolved path is re-checked to lie under the root;
 * - files are created without execute permissions.
 */
final class LocalDriver implements StorageDriverContract
{
    /** Maximum length of a single path segment. */
    private const MAX_SEGMENT_LENGTH = 255;

    private readonly string $root;

    public function __construct(
        string $root,
        private readonly bool $isPublic = false,
        private readonly int $fileMode = 0o600,
        private readonly int $directoryMode = 0o700,
        private readonly ?string $publicUrl = null,
    ) {
        if (!is_dir($root)) {
            if (!@mkdir($root, $this->directoryMode, true) && !is_dir($root)) {
                throw new StorageException(sprintf('Failed to create storage root [%s].', $root));
            }
        }

        $resolved = realpath($root);

        if ($resolved === false || !is_dir($resolved)) {
            throw new StorageException(sprintf('Storage root [%s] is not a directory.', $root));
        }

        $this->root = $resolved;

        if (!is_writable($this->root)) {
            throw new StorageException(sprintf('Storage root [%s] is not writable.', $this->root));
        }

        @chmod($this->root, $this->directoryMode);
    }

    public function root(): string
    {
        return $this->root;
    }

    public function isPublic(): bool
    {
        return $this->isPublic;
    }

    public function path(string $path): string
    {
        $segments = $this->validateSegments($path);

        $current = $this->root;

        foreach ($segments as $segment) {
            $next = $current.'/'.$segment;

            if (is_file($next) || is_dir($next) || is_link($next)) {
                if (is_link($next)) {
                    throw new StorageException(sprintf('Symbolic links are not allowed in storage paths: %s.', $path));
                }

                $real = realpath($next);

                if ($real === false) {
                    throw new StorageException(sprintf('Unable to resolve storage path: %s.', $path));
                }

                if (!$this->isUnderRoot($real)) {
                    throw new StorageException(sprintf('Access outside storage root is forbidden: %s.', $path));
                }

                $current = $real;
            } else {
                // The remainder does not exist yet. Every segment was
                // validated above, so appending it cannot escape the root.
                $current = $next;
            }
        }

        return $current;
    }

    /**
     * @param string|resource $contents file contents or an open readable stream
     */
    public function put(string $path, $contents): void
    {
        $target = $this->path($path);

        if (file_exists($target) && is_link($target)) {
            throw new StorageException(sprintf('Refusing to write through a symbolic link: %s.', $path));
        }

        if (file_exists($target)) {
            $real = realpath($target);

            if ($real === false || $real !== $target) {
                throw new StorageException(sprintf('Target escapes storage root: %s.', $path));
            }

            if (is_dir($target)) {
                throw new StorageException(sprintf('Target is a directory, not a file: %s.', $path));
            }
        }

        $parent = dirname($target);

        if (!is_dir($parent)) {
            if (!@mkdir($parent, $this->directoryMode, true) && !is_dir($parent)) {
                throw new StorageException(sprintf('Failed to create directory: %s.', $parent));
            }
        }

        $realParent = realpath($parent);

        if ($realParent === false || !$this->isUnderRoot($realParent)) {
            throw new StorageException(sprintf('Directory escapes storage root: %s.', $parent));
        }

        if (is_string($contents)) {
            if (file_put_contents($target, $contents, LOCK_EX) === false) {
                throw new StorageException(sprintf('Failed to write file: %s.', $path));
            }
        } elseif (is_resource($contents)) {
            $handle = @fopen($target, 'wb');

            if ($handle === false) {
                throw new StorageException(sprintf('Failed to open file for writing: %s.', $path));
            }

            try {
                if (stream_copy_to_stream($contents, $handle) === false) {
                    throw new StorageException(sprintf('Failed to write file: %s.', $path));
                }
            } finally {
                fclose($handle);
            }
        } else {
            throw new StorageException(sprintf('Invalid file contents for: %s.', $path));
        }

        clearstatcache(true, $target);

        // Files are never stored with execute permissions.
        @chmod($target, $this->fileMode);
    }

    public function get(string $path): string
    {
        $target = $this->requireFile($path);

        $contents = file_get_contents($target);

        if ($contents === false) {
            throw new StorageException(sprintf('Failed to read file: %s.', $path));
        }

        return $contents;
    }

    /**
     * @return resource
     */
    public function readStream(string $path)
    {
        $target = $this->requireFile($path);

        $handle = @fopen($target, 'rb');

        if ($handle === false) {
            throw new StorageException(sprintf('Failed to open stream: %s.', $path));
        }

        return $handle;
    }

    public function has(string $path): bool
    {
        try {
            $target = $this->path($path);
        } catch (StorageException $e) {
            return false;
        }

        return is_file($target) || is_dir($target);
    }

    public function size(string $path): int
    {
        $target = $this->requireFile($path);

        $size = filesize($target);

        if ($size === false) {
            throw new StorageException(sprintf('Failed to determine size of: %s.', $path));
        }

        return $size;
    }

    public function mimeType(string $path): string
    {
        $target = $this->requireFile($path);

        return (new MimeTypeDetector())->detectFile($target);
    }

    public function lastModified(string $path): int
    {
        $target = $this->requireFile($path);

        $modified = filemtime($target);

        if ($modified === false) {
            throw new StorageException(sprintf('Failed to determine modification time of: %s.', $path));
        }

        return $modified;
    }

    public function delete(string $path): bool
    {
        try {
            $target = $this->path($path);
        } catch (StorageException $e) {
            return false;
        }

        if (!is_file($target)) {
            return false;
        }

        return @unlink($target) === true;
    }

    public function copy(string $from, string $to): void
    {
        $source = $this->requireFile($from);
        $target = $this->path($to);

        if (file_exists($target) && is_link($target)) {
            throw new StorageException(sprintf('Refusing to copy through a symbolic link: %s.', $to));
        }

        $this->ensureParentDirectory($target);

        if (@copy($source, $target) === false) {
            throw new StorageException(sprintf('Failed to copy %s to %s.', $from, $to));
        }

        clearstatcache(true, $target);
        @chmod($target, $this->fileMode);
    }

    public function move(string $from, string $to): void
    {
        $source = $this->requireFile($from);
        $target = $this->path($to);

        if (file_exists($target) && is_link($target)) {
            throw new StorageException(sprintf('Refusing to move through a symbolic link: %s.', $to));
        }

        $this->ensureParentDirectory($target);

        if (@rename($source, $target) === false) {
            throw new StorageException(sprintf('Failed to move %s to %s.', $from, $to));
        }

        clearstatcache(true, $target);
        @chmod($target, $this->fileMode);
    }

    public function makeDirectory(string $path, bool $recursive = false): void
    {
        $segments = $this->validateSegments($path);

        $current = $this->root;
        /** @var list<string> */
        $created = [];

        foreach ($segments as $segment) {
            $next = $current.'/'.$segment;

            if (is_link($next)) {
                throw new StorageException(sprintf('Symbolic links are not allowed: %s.', $path));
            }

            if (is_file($next)) {
                throw new StorageException(sprintf('Cannot create directory; a file exists at: %s.', $path));
            }

            if (!is_dir($next)) {
                if (!@mkdir($next, $this->directoryMode, $recursive) && !is_dir($next)) {
                    throw new StorageException(sprintf('Failed to create directory: %s.', $next));
                }

                $created[] = $next;
            }

            $real = realpath($next);

            if ($real === false || !$this->isUnderRoot($real)) {
                throw new StorageException(sprintf('Directory escapes storage root: %s.', $path));
            }

            $current = $real;
        }

        foreach ($created as $directory) {
            @chmod($directory, $this->directoryMode);
        }
    }

    public function deleteDirectory(string $path): void
    {
        $target = $this->requireDirectory($path);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($target, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $pathname = $item->getPathname();

            if ($item->isLink()) {
                throw new StorageException(sprintf('Symbolic links are not allowed: %s.', $pathname));
            }

            if ($item->isDir()) {
                @rmdir($pathname);
            } else {
                @unlink($pathname);
            }
        }

        @rmdir($target);
    }

    /** @return list<array{path: string, type: 'file'|'dir', size: int, mime_type: string|null, last_modified: int|null}> */
    public function listContents(string $path = ''): array
    {
        if ($path === '') {
            $target = $this->root;
            $prefix = '';
        } else {
            $target = $this->requireDirectory($path);
            $prefix = rtrim($path, '/').'/';
        }

        $entries = @scandir($target);

        if ($entries === false) {
            throw new StorageException(sprintf('Failed to list directory: %s.', $path));
        }

        $contents = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $target.'/'.$entry;

            if (is_link($full)) {
                continue;
            }

            $relative = $prefix.$entry;

            if (is_dir($full)) {
                $contents[] = [
                    'path' => $relative,
                    'type' => 'dir',
                    'size' => 0,
                    'mime_type' => null,
                    'last_modified' => filemtime($full),
                ];
            } elseif (is_file($full)) {
                $contents[] = [
                    'path' => $relative,
                    'type' => 'file',
                    'size' => filesize($full),
                    'mime_type' => (new MimeTypeDetector())->detectFile($full),
                    'last_modified' => filemtime($full),
                ];
            }
        }

        usort($contents, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

        return $contents;
    }

    public function url(string $path): ?string
    {
        if (!$this->isPublic || $this->publicUrl === null) {
            return null;
        }

        // A URL must be as safe as a filesystem path: no traversal, no
        // absolute paths, no control characters.
        $segments = $this->validateSegments($path);
        $encoded = implode('/', array_map('rawurlencode', $segments));

        return rtrim($this->publicUrl, '/').'/'.$encoded;
    }

    /**
     * Validate a user-supplied path and return its segments.
     *
     * Rejects empty paths, NUL bytes, absolute paths, `.`/`..` segments,
     * empty segments, overlong segments, and control characters.
     *
     * @return list<string>
     */
    private function validateSegments(string $path): array
    {
        if ($path === '') {
            throw new StorageException('Storage path must not be empty.');
        }

        if (str_contains($path, "\0")) {
            throw new StorageException(sprintf('Storage path must not contain NUL bytes: %s.', $path));
        }

        $normalized = str_replace('\\', '/', $path);

        if ($normalized[0] === '/' || preg_match('#^[a-zA-Z]:[\\/]#', $normalized) === 1) {
            throw new StorageException(sprintf('Absolute paths are not allowed: %s.', $path));
        }

        $segments = explode('/', $normalized);

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new StorageException(sprintf('Unsafe path segment [%s] in %s.', $segment, $path));
            }

            if (strlen($segment) > self::MAX_SEGMENT_LENGTH) {
                throw new StorageException(sprintf('Path segment exceeds 255 characters: %s.', $path));
            }

            if (preg_match('/[\x00-\x1f\x7f]/', $segment) === 1) {
                throw new StorageException(sprintf('Unsafe characters in storage path: %s.', $path));
            }
        }

        return $segments;
    }

    private function requireFile(string $path): string
    {
        $target = $this->path($path);

        if (!is_file($target)) {
            throw new StorageException(sprintf('File not found: %s.', $path));
        }

        return $target;
    }

    private function requireDirectory(string $path): string
    {
        $target = $this->path($path);

        if (!is_dir($target)) {
            throw new StorageException(sprintf('Directory not found: %s.', $path));
        }

        return $target;
    }

    private function ensureParentDirectory(string $target): void
    {
        $parent = dirname($target);

        if (!is_dir($parent)) {
            if (!@mkdir($parent, $this->directoryMode, true) && !is_dir($parent)) {
                throw new StorageException(sprintf('Failed to create directory: %s.', $parent));
            }
        }

        $realParent = realpath($parent);

        if ($realParent === false || !$this->isUnderRoot($realParent)) {
            throw new StorageException(sprintf('Directory escapes storage root: %s.', $parent));
        }
    }

    private function isUnderRoot(string $realPath): bool
    {
        return $realPath === $this->root || str_starts_with($realPath, $this->root.'/');
    }
}
