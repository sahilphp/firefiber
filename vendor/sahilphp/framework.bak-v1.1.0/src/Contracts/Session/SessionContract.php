<?php

declare(strict_types=1);

namespace Sahil\Contracts\Session;

use Sahil\Http\Request;
use Sahil\Http\Response;

/**
 * Session management contract.
 *
 * Provides a high-level interface for session management with security
 * features like ID rotation, expiration, and CSRF token handling.
 */
interface SessionContract
{
    /**
     * Get a session value.
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Set a session value.
     */
    public function put(string $key, mixed $value): void;

    /**
     * Check if a key exists in the session.
     */
    public function has(string $key): bool;

    /**
     * Remove a key from the session.
     */
    public function forget(string $key): void;

    /**
     * Get all session data.
     *
     * @return array<string, mixed>
     */
    public function all(): array;

    /**
     * Clear all session data (except CSRF token).
     */
    public function flush(): void;

    /**
     * Get the session ID.
     */
    public function id(): string;

    /**
     * Regenerate the session ID to prevent session fixation.
     *
     * @param bool $destroy whether to destroy the old session data
     */
    public function regenerate(bool $destroy = false): void;

    /**
     * Save the session data.
     */
    public function save(): void;

    /**
     * Determine if the session has been started.
     */
    public function isStarted(): bool;

    /**
     * Get the CSRF token for the current session.
     */
    public function token(): string;

    /**
     * Validate a CSRF token.
     */
    public function validateToken(string $token): bool;

    /**
     * Regenerate the CSRF token.
     */
    public function regenerateToken(): void;

    /**
     * Get the session name (cookie name).
     */
    public function getName(): string;

    /**
     * Set the session name (cookie name).
     */
    public function setName(string $name): void;

    /**
     * Start the session from an incoming request (reads the session cookie
     * and loads the stored data, or mints a new session ID).
     */
    public function startFromRequest(Request $request): void;

    /**
     * Attach the session cookie to an outgoing response.
     */
    public function applyCookieToResponse(Response $response): Response;

    /**
     * Remove expired session data (garbage collection).
     */
    public function gc(): void;
}
