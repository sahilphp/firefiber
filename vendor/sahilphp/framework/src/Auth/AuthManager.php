<?php

declare(strict_types=1);

namespace Sahil\Auth;

use Sahil\Contracts\Auth\AuthenticatableContract;
use Sahil\Contracts\Auth\AuthStateContract;
use Sahil\Contracts\Auth\UserProviderContract;

/**
 * Authentication manager.
 *
 * Handles user authentication, login, logout, and session management.
 * Integrates with the user provider to retrieve and validate users,
 * and with the auth state to persist authentication across requests.
 *
 * Security features:
 * - Session fixation protection via ID rotation on login
 * - Brute-force protection via rate limiting
 * - Password rehashing on successful login
 * - Secure remember me functionality
 */
final class AuthManager
{
    /** @var array<string, mixed> */
    private array $passwordOptions = [];

    public function __construct(
        private readonly UserProviderContract $provider,
        private readonly AuthStateContract $state,
        private readonly RateLimiter $rateLimiter,
    ) {
    }

    /**
     * Set password hashing options.
     *
     * @param array<string, mixed> $options
     */
    public function setPasswordOptions(array $options): void
    {
        $this->passwordOptions = $options;
    }

    /**
     * Attempt to authenticate a user with the given credentials.
     *
     * @param array<string, mixed> $credentials
     * @return bool true if authentication succeeded
     */
    public function attempt(array $credentials, bool $remember = false): bool
    {
        // Apply rate limiting to prevent brute-force attacks
        $this->rateLimiter->attempt($this->getLoginKey($credentials));

        if ($this->rateLimiter->tooManyAttempts($this->getLoginKey($credentials))) {
            return false;
        }

        $user = $this->provider->retrieveByCredentials($credentials);

        if ($user === null) {
            // User not found - increment rate limiter
            $this->rateLimiter->increment($this->getLoginKey($credentials));

            return false;
        }

        if (!$this->provider->validateCredentials($user, $credentials)) {
            // Invalid password - increment rate limiter
            $this->rateLimiter->increment($this->getLoginKey($credentials));

            return false;
        }

        // Authentication successful - clear rate limiter
        $this->rateLimiter->clear($this->getLoginKey($credentials));

        // Rehash password if needed
        $this->provider->rehashPasswordIfRequired(
            $user,
            $credentials['password'],
            $this->passwordOptions,
        );

        // Set the authenticated user
        $this->state->setUser($user);

        // Handle remember me functionality
        if ($remember) {
            $token = Password::generateToken();
            $this->state->remember($user, $token);
        }

        return true;
    }

    /**
     * Log a user in without credential validation.
     *
     * Useful for trusted contexts (e.g., admin impersonation, OAuth callback).
     */
    public function login(AuthenticatableContract $user, bool $remember = false): void
    {
        $this->state->setUser($user);

        if ($remember) {
            $token = Password::generateToken();
            $this->state->remember($user, $token);
        }
    }

    /**
     * Log the user out.
     */
    public function logout(): void
    {
        $user = $this->state->user();

        if ($user !== null) {
            $this->state->forgetRememberToken($user);
        }

        $this->state->clear();
    }

    /**
     * Get the currently authenticated user.
     */
    public function user(): ?AuthenticatableContract
    {
        return $this->state->user();
    }

    /**
     * Get the ID of the currently authenticated user.
     *
     * @return int|string|null
     */
    public function id(): int|string|null
    {
        return $this->state->id();
    }

    /**
     * Check if a user is authenticated.
     */
    public function check(): bool
    {
        return $this->state->check();
    }

    /**
     * Check if a user is a guest (not authenticated).
     */
    public function guest(): bool
    {
        return $this->state->guest();
    }

    /**
     * Get the rate limiter key for login attempts.
     *
     * Uses the login identifier (email, username, etc.) to track attempts
     * per user, preventing user enumeration while still limiting brute force.
     *
     * @param array<string, mixed> $credentials
     */
    private function getLoginKey(array $credentials): string
    {
        // Extract the identifier (email, username, etc.)
        $identifier = $credentials['email'] ?? $credentials['username'] ?? 'unknown';

        return 'login:'.$identifier;
    }
}
