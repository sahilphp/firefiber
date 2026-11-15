<?php

declare(strict_types=1);

namespace Sahil\Storage;

use Throwable;

/**
 * Server-side MIME type detection.
 *
 * Types are always detected from file content (magic bytes) via finfo,
 * never from client-supplied headers or extensions.
 */
final class MimeTypeDetector
{
    /** Public extension map: server-detected MIME type → safe stored extension. */
    public const EXTENSION_MAP = [
        'image/apng' => 'apng',
        'image/avif' => 'avif',
        'image/gif' => 'gif',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/svg+xml' => 'svg',
        'image/webp' => 'webp',
        'text/plain' => 'txt',
        'text/x-php' => 'php',
        'text/csv' => 'csv',
        'application/pdf' => 'pdf',
        'application/json' => 'json',
        'application/zip' => 'zip',
        'application/x-gzip' => 'gz',
        'application/x-tar' => 'tar',
        'audio/mpeg' => 'mp3',
        'audio/wav' => 'wav',
        'audio/ogg' => 'ogg',
        'audio/mp4' => 'm4a',
        'video/mp4' => 'mp4',
        'video/mpeg' => 'mpg',
        'video/quicktime' => 'mov',
        'video/webm' => 'webm',
        'video/ogg' => 'ogv',
    ];

    /** Extensions that make a stored file directly executable by common web servers. */
    public const EXECUTABLE_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'phtml', 'phar',
        'cgi', 'pl', 'py', 'rb', 'jsp', 'asp', 'aspx',
        'exe', 'bat', 'cmd', 'com', 'sh',
    ];

    public function detectFile(string $file): string
    {
        $mime = $this->detectWithFinfoFile($file);

        if ($mime === false || $mime === '') {
            $mime = $this->detectFallback($file);
        }

        if ($mime === false || $mime === '') {
            throw new StorageException(sprintf('Unable to detect MIME type of %s.', $file));
        }

        return (string) $mime;
    }

    /** Extension derived from a server-detected MIME type, or null when unknown. */
    public static function extension(string $mimeType): ?string
    {
        return self::EXTENSION_MAP[$mimeType] ?? null;
    }

    /** Whether an extension derived from server content is directly executable. */
    public static function isExecutableExtension(string $extension): bool
    {
        return in_array(strtolower((string) $extension), self::EXECUTABLE_EXTENSIONS, true);
    }

    private function detectWithFinfoFile(string $path): false|string
    {
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            return false;
        }

        try {
            return @finfo_file($finfo, $path);
        } catch (Throwable $e) {
            return false;
        } finally {
            @finfo_close($finfo);
        }
    }

    private function detectFallback(string $path): false|string
    {
        try {
            return @mime_content_type($path);
        } catch (Throwable $e) {
            return false;
        }
    }
}
