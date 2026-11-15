<?php

declare(strict_types=1);

namespace Sahil\Contracts\Storage;

/**
 * Per-disk storage driver.
 *
 * This is the framework's storage extension point: every storage backend
 * (local filesystem, S3, ...) implements this contract so the manager and
 * the rest of the application never depend on a concrete backend.
 *
 * Implementations MUST treat every path as relative to their own root and
 * MUST never allow resolution outside that root (no path traversal, no
 * symlink escapes, no absolute paths).
 */
interface StorageDriverContract
{
    /** The absolute, canonical root of this disk. */
    public function root(): string;

    /** Whether files on this disk may be served publicly. */
    public function isPublic(): bool;

    /** Resolve and validate a path relative to the root, returning its absolute form. */
    public function path(string $path): string;

    /**
     * Write (create or overwrite) a file.
     *
     * @param string|resource $contents file contents or an open readable stream
     */
    public function put(string $path, $contents): void;

    /** Read a file's contents as a string. */
    public function get(string $path): string;

    /**
     * Open a readable stream to the file.
     *
     * @return resource
     */
    public function readStream(string $path);

    /** Whether the path exists. */
    public function has(string $path): bool;

    /** File size in bytes. */
    public function size(string $path): int;

    /** Server-detected MIME type of the file (never client-supplied). */
    public function mimeType(string $path): string;

    /** Last modification time as a UNIX timestamp. */
    public function lastModified(string $path): int;

    /** Delete a file; returns false when nothing existed. */
    public function delete(string $path): bool;

    /** Copy a file within the disk. */
    public function copy(string $from, string $to): void;

    /** Move a file within the disk. */
    public function move(string $from, string $to): void;

    /** Create a directory (recursively when requested). */
    public function makeDirectory(string $path, bool $recursive = false): void;

    /** Recursively delete a directory. */
    public function deleteDirectory(string $path): void;

    /**
     * List the contents of a directory.
     *
     * @return list<array{path: string, type: 'file'|'dir', size: int, mime_type: string|null, last_modified: int|null}>
     */
    public function listContents(string $path = ''): array;

    /** Public URL for the path, or null when the disk is not public. */
    public function url(string $path): ?string;
}
