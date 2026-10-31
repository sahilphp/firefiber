<?php

declare(strict_types=1);

namespace Sahil\Storage;

use Closure;
use Sahil\Config\Repository;
use Sahil\Contracts\Storage\StorageContract;
use Sahil\Contracts\Storage\StorageDriverContract;

/**
 * Storage manager with pluggable disk drivers.
 *
 * Disks are declared in the `storage.disks` configuration namespace and the
 * default disk comes from `storage.default`. Calls without an explicit disk
 * operate on the default disk.
 *
 * Remote backends are supported by registering a driver factory through
 * {@see extend()}: the factory receives the disk configuration array and
 * must return a {@see StorageDriverContract}.
 */
final class StorageManager implements StorageContract
{
    /** @var array<string, StorageDriverContract> */
    private array $disks = [];

    /** @var array<string, Closure(array<string, mixed>): mixed> */
    private array $extensions = [];

    private ?string $defaultDisk = null;

    /**
     * @param string|null $publicPath the web server's document root; when known,
     *                                a private local disk inside it is refused
     */
    public function __construct(
        private readonly Repository $config,
        private readonly ?string $publicPath = null,
    ) {
    }

    public function disk(?string $name = null): StorageDriverContract
    {
        $name ??= $this->resolveDefaultDisk();

        $diskConfig = $this->config->get('storage.disks.'.$name);

        if (!is_array($diskConfig)) {
            throw new StorageException(sprintf('Storage disk [%s] is not defined.', $name));
        }

        if (isset($this->disks[$name])) {
            return $this->disks[$name];
        }

        $driver = (string) ($diskConfig['driver'] ?? 'local');

        if (isset($this->extensions[$driver])) {
            $instance = ($this->extensions[$driver])($diskConfig);
        } elseif ($driver === 'local') {
            $this->assertLocalLayout($name, $diskConfig);
            $instance = $this->createLocalDriver($diskConfig);
        } else {
            throw new StorageException(sprintf('Unsupported storage driver [%s].', $driver));
        }

        if (!($instance instanceof StorageDriverContract)) {
            throw new StorageException(sprintf(
                'Driver factory for driver [%s] did not return a storage driver.',
                $driver,
            ));
        }

        return $this->disks[$name] = $instance;
    }

    /** Register or override the factory for a driver (remote adapters, etc.). */
    public function extend(string $driver, Closure $factory): void
    {
        $this->extensions[$driver] = $factory;
    }

    public function put(string $path, $contents, ?string $disk = null): void
    {
        $this->disk($disk)->put($path, $contents);
    }

    public function get(string $path, ?string $disk = null): string
    {
        return $this->disk($disk)->get($path);
    }

    public function readStream(string $path, ?string $disk = null)
    {
        return $this->disk($disk)->readStream($path);
    }

    public function has(string $path, ?string $disk = null): bool
    {
        return $this->disk($disk)->has($path);
    }

    public function size(string $path, ?string $disk = null): int
    {
        return $this->disk($disk)->size($path);
    }

    public function mimeType(string $path, ?string $disk = null): string
    {
        return $this->disk($disk)->mimeType($path);
    }

    public function lastModified(string $path, ?string $disk = null): int
    {
        return $this->disk($disk)->lastModified($path);
    }

    public function delete(string $path, ?string $disk = null): bool
    {
        return $this->disk($disk)->delete($path);
    }

    public function copy(string $from, string $to, ?string $disk = null): void
    {
        $this->disk($disk)->copy($from, $to);
    }

    public function move(string $from, string $to, ?string $disk = null): void
    {
        $this->disk($disk)->move($from, $to);
    }

    public function makeDirectory(string $path, bool $recursive = false, ?string $disk = null): void
    {
        $this->disk($disk)->makeDirectory($path, $recursive);
    }

    public function deleteDirectory(string $path, ?string $disk = null): void
    {
        $this->disk($disk)->deleteDirectory($path);
    }

    /** @return list<array{path: string, type: 'file'|'dir', size: int, mime_type: string|null, last_modified: int|null}> */
    public function listContents(string $path = '', ?string $disk = null): array
    {
        return $this->disk($disk)->listContents($path);
    }

    public function url(string $path, ?string $disk = null): ?string
    {
        return $this->disk($disk)->url($path);
    }

    public function upload(string $inputName, array $options = [], ?string $disk = null): StoredFile
    {
        $name = $disk ?? $this->resolveDefaultDisk();
        $driver = $this->disk($name);

        $maxSize = (int) ($options['max_size'] ?? $this->config->get('storage.uploads.max_size', 10_485_760));

        $allowedMimes = $options['allowed_mimes'] ?? $this->config->get('storage.uploads.allowed_mimes', []);
        if (!is_array($allowedMimes)) {
            throw new StorageException('Upload option [allowed_mimes] must be a list of MIME types.');
        }
        foreach ($allowedMimes as $mime) {
            if (!is_string($mime) || $mime === '') {
                throw new StorageException('Upload option [allowed_mimes] must contain non-empty strings.');
            }
        }

        $subPath = (string) ($options['path'] ?? '');

        if ($maxSize <= 0) {
            throw new StorageException('Upload option [max_size] must be a positive integer.');
        }

        $files = $_FILES;
        $upload = $files[$inputName] ?? null;

        if (!is_array($upload)) {
            throw new UploadException(sprintf('No file was uploaded for input [%s].', $inputName));
        }

        $error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error !== UPLOAD_ERR_OK) {
            throw new UploadException(match ($error) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the maximum allowed size.',
                UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded.',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
                UPLOAD_ERR_NO_TMP_DIR => 'The server lacks a temporary folder for uploads.',
                UPLOAD_ERR_CANT_WRITE => 'The uploaded file could not be written to disk.',
                UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.',
                default => 'The file upload failed with an unknown error.',
            });
        }

        $tmpName = $upload['tmp_name'] ?? '';

        if (!is_string($tmpName) || $tmpName === '' || !is_file($tmpName)) {
            throw new UploadException('The uploaded file is not available.');
        }

        // The declared $_FILES size is advisory: the actual stream size is authoritative.
        $stream = @fopen($tmpName, 'rb');

        if ($stream === false) {
            throw new UploadException('The uploaded file cannot be read.');
        }

        try {
            $actualSize = $this->measureStreamSize($stream);
        } finally {
            fclose($stream);
        }

        if ($actualSize <= 0) {
            throw new UploadException('The uploaded file is empty.');
        }

        $declaredSize = (int) ($upload['size'] ?? 0);

        if ($declaredSize !== $actualSize) {
            throw new UploadException('The uploaded file size cannot be verified.');
        }

        if ($actualSize > $maxSize) {
            throw new UploadException(sprintf(
                'The uploaded file exceeds the maximum allowed size of %d bytes.',
                $maxSize,
            ));
        }

        // Type is detected from file content (magic bytes); client-supplied
        // MIME types and extensions are never trusted.
        $detector = new MimeTypeDetector();
        $mimeType = $detector->detectFile($tmpName);

        if (!in_array($mimeType, $allowedMimes, true)) {
            throw new UploadException(sprintf('Files of type [%s] are not permitted.', $mimeType));
        }

        $extension = $detector->extension($mimeType) ?? 'bin';

        // An uploaded file is never given an executable extension, whichever
        // options the caller passes: a web server must never run one.
        if (MimeTypeDetector::isExecutableExtension($extension)) {
            throw new UploadException(sprintf('Files with extension [%s] are not permitted.', $extension));
        }

        $originalName = $this->sanitizeFileName((string) ($upload['name'] ?? ''));

        if ($originalName === '') {
            $originalName = 'upload';
        }

        $storedName = bin2hex(random_bytes(16)).'.'.$extension;
        $targetPath = $subPath === '' ? $storedName : rtrim($subPath, '/').'/'.$storedName;

        if ($subPath !== '') {
            $driver->makeDirectory($subPath, true);
        }

        $uploadStream = @fopen($tmpName, 'rb');

        if ($uploadStream === false) {
            throw new UploadException('The uploaded file cannot be read.');
        }

        try {
            $driver->put($targetPath, $uploadStream);
        } finally {
            fclose($uploadStream);
        }

        // Verify what the DRIVER stored, not a local path: the same pipeline
        // therefore works for remote adapters (S3 and friends).
        try {
            $storedMime = $driver->mimeType($targetPath);
            $storedSize = $driver->size($targetPath);
        } catch (StorageException $failure) {
            $this->discard($driver, $targetPath);
            throw new UploadException('The uploaded file failed verification.', 0, $failure);
        }

        if ($storedMime !== $mimeType) {
            $this->discard($driver, $targetPath);
            throw new UploadException('The uploaded file failed verification.');
        }

        if ($storedSize !== $actualSize) {
            $this->discard($driver, $targetPath);
            throw new UploadException('The uploaded file failed size verification.');
        }

        return new StoredFile(
            disk: $name,
            path: $targetPath,
            storedName: $storedName,
            originalName: $originalName,
            size: $storedSize,
            mimeType: $storedMime,
            url: $driver->url($targetPath),
        );
    }

    /**
     * Measure the real size of a readable stream without trusting metadata.
     *
     * @param resource $handle
     */
    private function measureStreamSize($handle): int
    {
        $size = 0;

        while (!feof($handle)) {
            $chunk = fread($handle, 1024 * 1024);

            if ($chunk === false || $chunk === '') {
                break;
            }

            $size += strlen($chunk);
        }

        return $size;
    }

    /**
     * Sanitize a client-supplied file name: directory parts are stripped,
     * unsafe characters are collapsed, and reserved names are rejected.
     */
    private function sanitizeFileName(string $name): string
    {
        $name = basename($name);
        $name = str_replace("\0", '', $name);
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name);
        $name = trim($name, '.-');

        if ($name === '' || $name === '.' || $name === '..') {
            return '';
        }

        return substr($name, 0, 200);
    }

    /**
     * Remove a stored upload after a failed verification, tolerating a
     * driver that cannot delete (the file is already unusable).
     */
    private function discard(StorageDriverContract $driver, string $path): void
    {
        try {
            $driver->delete($path);
        } catch (StorageException) {
            // Best effort: the caller is already raising the real failure.
        }
    }

    /**
     * A private disk must never resolve inside a path the web server serves.
     *
     * Two sources of "served path" are considered: the document root, when
     * it is known, and every disk configured as public. Missing roots are
     * compared lexically, so a not-yet-created directory cannot slip
     * through.
     *
     * @param array<string, mixed> $config
     */
    private function assertLocalLayout(string $name, array $config): void
    {
        if ((bool) ($config['public'] ?? false)) {
            return;
        }

        $root = $this->canonicalPath((string) ($config['root'] ?? storage_path('uploads')));

        if ($root === null) {
            return;
        }

        foreach ($this->publicRoots($name) as $publicRoot) {
            if ($root === $publicRoot || str_starts_with($root, $publicRoot.'/')) {
                throw new StorageException(sprintf(
                    'Private disk [%s] resolves inside the publicly served path [%s]; private files must never be web-accessible.',
                    $name,
                    $publicRoot,
                ));
            }
        }
    }

    /**
     * Paths a web server may serve: the document root, when known, plus every
     * local disk configured as public.
     *
     * @return list<string>
     */
    private function publicRoots(string $except): array
    {
        $roots = [];

        if ($this->publicPath !== null) {
            $documentRoot = $this->canonicalPath($this->publicPath);

            if ($documentRoot !== null) {
                $roots[] = $documentRoot;
            }
        }

        $disks = $this->config->get('storage.disks', []);

        if (is_array($disks)) {
            foreach ($disks as $diskName => $disk) {
                if ((string) $diskName === $except || !is_array($disk)) {
                    continue;
                }

                if (!(bool) ($disk['public'] ?? false)) {
                    continue;
                }

                if ((string) ($disk['driver'] ?? 'local') !== 'local') {
                    continue;
                }

                $root = $this->canonicalPath((string) ($disk['root'] ?? ''));

                if ($root !== null) {
                    $roots[] = $root;
                }
            }
        }

        return array_values(array_unique($roots));
    }

    /**
     * Canonical absolute form of a path. Existing paths are resolved through
     * realpath (symlinks included); missing ones are normalised lexically so
     * the layout check also works before a directory is created.
     */
    private function canonicalPath(string $path): ?string
    {
        if ($path === '') {
            return null;
        }

        $real = realpath($path);

        if ($real !== false) {
            return rtrim($real, '/');
        }

        $normalized = str_replace('\\', '/', $path);
        $absolute = str_starts_with($normalized, '/');
        $segments = [];

        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return ($absolute ? '/' : '').implode('/', $segments);
    }

    private function resolveDefaultDisk(): string
    {
        if ($this->defaultDisk !== null) {
            return $this->defaultDisk;
        }

        $default = $this->config->get('storage.default', 'local');

        if (!is_string($default) || $default === '') {
            throw new StorageException('No default storage disk is configured.');
        }

        return $this->defaultDisk = $default;
    }

    /**
     * Create a local filesystem driver from its disk configuration.
     *
     * @param array<string, mixed> $config
     */
    private function createLocalDriver(array $config): LocalDriver
    {
        $root = (string) ($config['root'] ?? storage_path('uploads'));

        $isPublic = (bool) ($config['public'] ?? false);
        $permissions = is_array($config['permissions'] ?? null) ? $config['permissions'] : [];

        $fileMode = (int) ($permissions['file'] ?? ($isPublic ? 0o644 : 0o600));
        $directoryMode = (int) ($permissions['directory'] ?? ($isPublic ? 0o755 : 0o700));
        $publicUrl = isset($config['url']) && is_string($config['url']) ? $config['url'] : null;

        return new LocalDriver($root, $isPublic, $fileMode, $directoryMode, $publicUrl);
    }
}
