<?php

declare(strict_types=1);

namespace Sahil\Firefiber;

use Closure;
use Sahil\Contracts\Cache\CacheContract;
use Sahil\Contracts\Storage\StorageContract;

final class ComponentCache
{
    public function __construct(
        private readonly ?CacheContract $cache = null,
        private readonly ?StorageContract $storage = null,
    ) {
    }

    public function forget(string $key): bool
    {
        if ($this->cache !== null) {
            return $this->cache->forget($key);
        }

        if ($this->storage !== null) {
            return $this->storage->delete($key);
        }

        return false;
    }

    public function cacheEnabled(): bool
    {
        return $this->cache !== null || $this->storage !== null;
    }

    public function key(Component $component, array $dimensions): string
    {
        return 'firefiber:render:'.hash('sha256', json_encode([
            'class' => $component::class,
            'id' => $component->id(),
            'state' => $component->state(),
            'properties' => $component->properties(),
            'dimensions' => $dimensions,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function remember(string $key, int $ttl, Closure $producer): mixed
    {
        if (!$this->cacheEnabled() || $ttl <= 0) {
            return $producer();
        }

        $cached = $this->get($key);

        if ($cached !== null) {
            return $cached;
        }

        $value = $producer();
        $this->put($key, $value, $ttl);

        return $value;
    }

    public function put(string $key, mixed $value, int $ttl): bool
    {
        if ($this->cache !== null) {
            return $this->cache->put($key, $value, $ttl);
        }

        if ($this->storage !== null) {
            $this->storage->put($key, json_encode($value, JSON_THROW_ON_ERROR));
            return true;
        }

        return false;
    }

    public function get(string $key): mixed
    {
        if ($this->cache !== null) {
            return $this->cache->get($key);
        }

        if ($this->storage !== null && $this->storage->has($key)) {
            return json_decode($this->storage->get($key), true);
        }

        return null;
    }
}
