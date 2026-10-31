<?php

declare(strict_types=1);

namespace Sahil\Contracts\Auth;

/**
 * Contract for authentication state management.
 *
 * The authentication state is responsible for tracking the currently
 * authenticated user across requests (typically via session storage).
 */
interface AuthStateContract
{
    /**
     * Get the currently authenticated user.
     */
    public function user(): ?AuthenticatableContract;

    /**
     * Get the ID of the currently authenticated user.
     *
     * @return int|string|null
     */
    public function id(): int|string|null;

    /**
     * Check if a user is authenticated.
     */
    public function check(): bool;

    /**
     * Check if a user is a guest (not authenticated).
     */
    public function guest(): bool;

    /**
     * Set the currently authenticated user.
     */
    public function setUser(AuthenticatableContract $user): void;

    /**
     * Clear the authentication state (logout).
     */
    public function clear(): void;

    /**
     * Remember the user for future requests (via "remember me" cookie).
     */
    public function remember(AuthenticatableContract $user, string $token): void;

    /**
     * Retrieve a remembered user by their token.
     */
    public function retrieveRememberedUser(string $token): ?AuthenticatableContract;

    /**
     * Clear the remember token for a user.
     */
    public function forgetRememberToken(AuthenticatableContract $user): void;
}
