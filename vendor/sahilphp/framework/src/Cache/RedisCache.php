<?php

declare(strict_types=1);

namespace Sahil\Cache;

use Sahil\Contracts\Cache\CacheContract;

/**
 * Redis cache driver implementing CacheContract.
 *
 * Requires the phpredis or predis extension to be installed.
 *
 * @example
 *   $cache = new Sahil\Cache\RedisCache(['host' => '127.0.0.1', 'port' => 6379]);
 *   $cache->put('key', 'value', 3600);
 *   $value = $cache->get('key');
 */
/**
 * @implements CacheContract<mixed>
 */
final class RedisCache implements CacheContract
{
    /** @var \Redis */
    private $redis;

    /** @param array $options Redis connection options (host, port, password, database) */
    public function __construct(array $options = [])
    {
        $this->redis = new \Redis();

        $host = $options['host'] ?? '127.0.0.1';
        $port = $options['port'] ?? 6379;
        $password = $options['password'] ?? null;
        $database = $options['database'] ?? 0;

        $this->redis->connect($host, $port);

        if ($password !== null) {
            $this->redis->auth($password);
        }

        $this->redis->select($database);
    }

    /** {@inheritDoc} */
    public function get(string $key): mixed
    {
        $value = $this->redis->get($key);

        return $value !== false ? $value : null;
    }

    /** {@inheritDoc} */
    public function put(string $key, mixed $value, int $ttl = 0): bool
    {
        if ($ttl > 0) {
            return $this->redis->setex($key, $ttl, serialize($value));
        }

        return $this->redis->set($key, serialize($value));
    }

    /** {@inheritDoc} */
    public function add(string $key, mixed $value, int $ttl = 0): bool
    {
        // CHECK-AND-SET: SET if not exists
        $result = $this->redis->setnx($key, serialize($value));

        if ($result && $ttl > 0) {
            $this->redis->expire($key, $ttl);
        }

        return $result !== false;
    }

    /** {@inheritDoc} */
    public function increment(string $key, int $amount = 1): mixed
    {
        return $this->redis->incrby($key, $amount);
    }

    /** {@inheritDoc} */
    public function decrement(string $key, int $amount = 1): mixed
    {
        return $this->redis->decrby($key, $amount);
    }

    /** {@inheritDoc} */
    public function forget(string $key): bool
    {
        return $this->redis->del($key) !== false;
    }

    /** {@inheritDoc} */
    public function flush(): bool
    {
        return $this->redis->flushdb() !== false;
    }

    /** {@inheritDoc} */
    public function has(string $key): bool
    {
        return $this->redis->exists($key) === 1;
    }

    /** {@inheritDoc} */
    public function ttL(string $key): int|false
    {
        $ttl = $this->redis->ttl($key);

        if ($ttl === -2) {
            return false; // Key does not exist
        }

        if ($ttl === -1) {
            return 0; // Key exists but has no expiration
        }

        return $ttl;
    }
}
