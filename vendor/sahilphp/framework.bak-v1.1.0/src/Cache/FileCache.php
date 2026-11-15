<?php

declare(strict_types=1);

namespace Sahil\Cache;

use Sahil\Contracts\Cache\CacheContract;

/**
 * File-based cache driver with expiration support.
 *
 * Stores cache items as serialized PHP files in a designated directory.
 * Each cache entry is stored as `cache_{hash}.php` containing
 * `return ['value' => ..., 'expires' => ...];`
 *
 * In production, consider using Redis, Memcached, or another
 * distributed cache for better performance and concurrency.
 */
/**
 * @implements CacheContract<mixed>
 */
final class FileCache implements CacheContract
{
    /** @var string */
    private $cachePath;

    /** @param string $cachePath Directory where cache files will be stored */
    public function __construct(string $cachePath)
    {
        $this->cachePath = rtrim($cachePath, '/') . '/';

        if (!is_dir($this->cachePath)) {
            mkdir($this->cachePath, 0755, true);
        }
    }

    /** {@inheritDoc} */
    public function get(string $key): mixed
    {
        $file = $this->cacheFile($key);

        if (!file_exists($file)) {
            return null;
        }

        $data = require $file;

        if (!isset($data['expires'])) {
            return $data['value'] ?? null;
        }

        $expires = (int) $data['expires'];
        $now = time();

        if ($now > $expires) {
            // Expired - remove the file
            @unlink($file);
            return null;
        }

        return $data['value'] ?? null;
    }

    /** {@inheritDoc} */
    public function put(string $key, mixed $value, int $ttl = 0): bool
    {
        $file = $this->cacheFile($key);
        $data = [
            'value' => $value,
            'expires' => $ttl === 0 ? 0 : time() + $ttl,
        ];

        $serialized = var_export($data, true);
        $result = file_put_contents($file, "<?php\nreturn {$serialized};");

        return $result !== false;
    }

    /** {@inheritDoc} */
    public function add(string $key, mixed $value, int $ttl = 0): bool
    {
        // Check if key exists first
        if ($this->has($key)) {
            return false;
        }

        return $this->put($key, $value, $ttl);
    }

    /** {@inheritDoc} */
    public function increment(string $key, int $amount = 1): mixed
    {
        $current = $this->get($key);

        if ($current === null || !is_numeric($current)) {
            return false;
        }

        $newValue = (int) $current + $amount;
        $this->put($key, $newValue, 0);

        return $newValue;
    }

    /** {@inheritDoc} */
    public function decrement(string $key, int $amount = 1): mixed
    {
        $current = $this->get($key);

        if ($current === null || !is_numeric($current)) {
            return false;
        }

        $newValue = max(0, (int) $current - $amount);
        $this->put($key, $newValue, 0);

        return $newValue;
    }

    /** {@inheritDoc} */
    public function forget(string $key): bool
    {
        $file = $this->cacheFile($key);

        return file_exists($file) && @unlink($file);
    }

    /** {@inheritDoc} */
    public function flush(): bool
    {
        $files = glob($this->cachePath . 'cache_*.php');

        if ($files === false || $files === []) {
            return true;
        }

        foreach ($files as $file) {
            @unlink($file);
        }

        return true;
    }

    /** {@inheritDoc} */
    public function has(string $key): bool
    {
        $file = $this->cacheFile($key);

        if (!file_exists($file)) {
            return false;
        }

        $data = require $file;

        if (!isset($data['expires'])) {
            return true;
        }

        $expires = (int) $data['expires'];
        $now = time();

        return $now <= $expires;
    }

    /** {@inheritDoc} */
    public function ttL(string $key): int|false
    {
        $file = $this->cacheFile($key);

        if (!file_exists($file)) {
            return false;
        }

        $data = require $file;

        if (!isset($data['expires'])) {
            return 0; // No expiration
        }

        $expires = (int) $data['expires'];
        $now = time();

        if ($now > $expires) {
            return false; // Expired
        }

        return max(0, $expires - $now);
    }

    /** Get the path for a cache file */
    private function cacheFile(string $key): string
    {
        $hash = md5($key);
        return $this->cachePath . 'cache_' . $hash . '.php';
    }
}
