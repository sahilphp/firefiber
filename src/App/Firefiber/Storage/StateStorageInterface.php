<?php

declare(strict_types=1);

namespace Sahil\Firefiber\Storage;

use JsonException;
use JsonSerializable;

/** @internal */
interface StateStorageInterface extends JsonSerializable
{
    /** Save component state with optional TTL expiration.
     *
     * @param string $componentId The component identifier
     * @param array $state The component state array
     * @param int $ttl Seconds until expiration (0 = no expiration)
     * @return bool True on success
     * @throws \Sahil\Firefiber\Exceptions\InvalidStateException When state is invalid or too large
     */
    public function saveState(string $componentId, array $state, int $ttl = 0): bool;

    /** Retrieve component state by ID.
     *
     * @param string $componentId The component identifier
     * @return array|null The component state, or null if not found/expired
     * @throws \Sahil\Firefiber\Exceptions\InvalidStateException When state is corrupted
     */
    public function retrieveState(string $componentId): array|null;

    /** Check whether a component has (non-expired) stored state.
     *
     * @param string $componentId The component identifier
     * @return bool True if component state exists and is not expired
     */
    public function hasState(string $componentId): bool;

    /** Delete component state.
     *
     * @param string $componentId The component identifier
     * @return bool True on success
     */
    public function deleteState(string $componentId): bool;

    /** Get the TTL/remaining lifetime for a component's stored state.
     *
     * @param string $componentId The component identifier
     * @return int|false Seconds remaining, or false if not found/expired
     */
    public function getTtl(string $componentId): int|false;

    /** Get the last modification time for a component's state.
     *
     * @param string $componentId The component identifier
     * @return int|false Unix timestamp, or false if not found
     */
    public function getLastModified(string $componentId): int|false;
}