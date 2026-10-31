<?php

declare(strict_types=1);

namespace Sahil\Firefiber\Assets;

/**
 * One allow-listed Firefiber browser asset.
 *
 * Instances are created by {@see AssetRepository}; the absolute path is kept
 * internal so controllers and templates can work with public URLs only.
 */
final class Asset
{
    /** @var string|null */
    private ?string $contents = null;

    /** @var string|null */
    private ?string $hash = null;

    /**
     * @param string $name          Public asset name, e.g. `runtime.min.js`
     * @param string $path          Trusted absolute file path inside the package
     * @param string $mimeType      Response content type
     * @param string $publicPrefix  URL prefix, e.g. `/firefiber/assets`
     * @param bool   $production    Whether this is an optimized production asset
     */
    public function __construct(
        private readonly string $name,
        private readonly string $path,
        private readonly string $mimeType,
        private readonly string $publicPrefix,
        private readonly bool $production = false,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * Trusted filesystem path. Intended for diagnostics and tests; never
     * serialize this into an HTTP response.
     */
    public function path(): string
    {
        return $this->path;
    }

    public function mimeType(): string
    {
        return $this->mimeType;
    }

    public function isProduction(): bool
    {
        return $this->production;
    }

    public function exists(): bool
    {
        return is_file($this->path) && is_readable($this->path);
    }

    /**
     * @throws AssetUnavailableException
     */
    public function contents(): string
    {
        if ($this->contents !== null) {
            return $this->contents;
        }

        if (!$this->exists()) {
            throw new AssetUnavailableException(
                sprintf('Firefiber asset [%s] is not available in the installed package.', $this->name),
            );
        }

        $contents = @file_get_contents($this->path);

        if ($contents === false) {
            throw new AssetUnavailableException(
                sprintf('Firefiber asset [%s] could not be read.', $this->name),
            );
        }

        return $this->contents = $contents;
    }

    public function size(): int
    {
        return strlen($this->contents());
    }

    public function lastModified(): int
    {
        if (!$this->exists()) {
            throw new AssetUnavailableException(
                sprintf('Firefiber asset [%s] is not available in the installed package.', $this->name),
            );
        }

        $modified = @filemtime($this->path);

        if ($modified === false) {
            throw new AssetUnavailableException(
                sprintf('Firefiber asset [%s] has no readable modification time.', $this->name),
            );
        }

        return $modified;
    }

    public function contentHash(): string
    {
        if ($this->hash === null) {
            $this->hash = hash('sha256', $this->contents());
        }

        return $this->hash;
    }

    /**
     * Short content identifier used for cache-busting URLs. It changes
     * whenever the file bytes change, including when a release rebuilds an
     * asset without changing its public alias.
     */
    public function version(): string
    {
        return substr($this->contentHash(), 0, 12);
    }

    public function etag(): string
    {
        return '"sha256-'.$this->contentHash().'"';
    }

    public function integrity(): string
    {
        return 'sha384-'.base64_encode(hash('sha384', $this->contents(), true));
    }

    public function publicUrl(bool $versioned = true): string
    {
        $url = rtrim($this->publicPrefix, '/').'/'.rawurlencode($this->name);

        return $versioned ? $url.'?v='.$this->version() : $url;
    }
}
