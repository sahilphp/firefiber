<?php

declare(strict_types=1);

namespace Sahil\Contracts\Cache;

/** @template TKey of array-key */
/** @template TValue */

interface CacheContract
{
    /** Retrieve an item from the cache.
     * @return TValue|null The cached value, or null if not found/expired
     */
    public function get(string $key): mixed;

    /** Store an item in the cache, with an optional expiration time.
     * @param string $key Cache key
     * @param TValue $value Value to cache
     * @param int $ttl Time-to-live in seconds (0 = no expiration)
     * @return bool True on success
     */
    public function put(string $key, mixed $value, int $ttl = 0): bool;

    /** Store an item in the cache if the key does not already exist.
     * @param string $key Cache key
     * @param TValue $value Value to cache
     * @param int $ttl Time-to-live in seconds (0 = no expiration)
     * @return bool True if the value was set, false if key already existed
     */
    public function add(string $key, mixed $value, int $ttl = 0): bool;

    /** Increment a numeric cache value.
     * @param string $key Cache key
     * @param int $amount Amount to increment by (default: 1)
     * @return int|false New value, or false on failure
     */
    public function increment(string $key, int $amount = 1): mixed;

    /** Decrement a numeric cache value.
     * @param string $key Cache key
     * @param int $amount Amount to decrement by (default: 1)
     * @return int|false New value, or false on failure
     */
    public function decrement(string $key, int $amount = 1): mixed;

    /** Remove an item from the cache.
     * @param string $key Cache key
     * @return bool True on success
     */
    public function forget(string $key): bool;

    /** Remove all items from the cache.
     * @return bool True on success
     */
    public function flush(): bool;

    /** Check if an item exists in the cache (and is not expired).
     * @param string $key Cache key
     * @return bool True if the item exists
     */
    public function has(string $key): bool;

    /** Get the remaining TTL for a cached item.
     * @param string $key Cache key
     * @return int|float|bool Seconds remaining, or false if not found/expired
     */
    public function ttL(string $key): mixed;
}
