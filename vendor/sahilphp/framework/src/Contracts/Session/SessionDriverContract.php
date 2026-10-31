<?php

declare(strict_types=1);

namespace Sahil\Contracts\Session;

/**
 * Session storage driver contract.
 *
 * Drivers handle the low-level persistence of session data. The framework
 * provides a file-based driver by default, but this interface allows for
 * alternative implementations (Redis, database, memcached, etc.).
 *
 * Security considerations:
 * - Drivers must serialize data safely to prevent object injection attacks
 * - Drivers must handle concurrent access gracefully (file locking, etc.)
 * - Drivers must support garbage collection of expired sessions
 * - Never store authentication secrets in plaintext
 */
interface SessionDriverContract
{
    /**
     * Read session data by ID.
     *
     * @return array<string, mixed> the session data, or empty array if not found
     */
    public function read(string $id): array;

    /**
     * Write session data for the given ID.
     *
     * @param array<string, mixed> $data
     */
    public function write(string $id, array $data): void;

    /**
     * Destroy a session by ID.
     */
    public function destroy(string $id): void;

    /**
     * Clean up expired sessions.
     *
     * @param int $lifetime maximum session lifetime in seconds
     */
    public function gc(int $lifetime): void;

    /**
     * Generate a cryptographically secure session ID.
     *
     * The ID must be unique and unpredictable to prevent session fixation
     * and session hijacking attacks.
     */
    public function generateId(): string;

    /**
     * Validate a session ID format.
     *
     * @return bool true if the ID is valid for this driver
     */
    public function isValidId(string $id): bool;
}
