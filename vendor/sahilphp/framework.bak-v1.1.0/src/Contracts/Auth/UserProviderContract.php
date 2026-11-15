<?php

declare(strict_types=1);

namespace Sahil\Contracts\Auth;

/**
 * Contract for user providers.
 *
 * User providers are responsible for retrieving users from storage
 * (database, cache, external service, etc.) and validating their credentials.
 */
interface UserProviderContract
{
    /**
     * Retrieve a user by their unique identifier.
     *
     * @param int|string $identifier
     */
    public function retrieveByIdentifier(int|string $identifier): ?AuthenticatableContract;

    /**
     * Retrieve a user by their credentials (e.g., email/username).
     *
     * This is used during login to find the user before password verification.
     *
     * @param array<string, mixed> $credentials
     */
    public function retrieveByCredentials(array $credentials): ?AuthenticatableContract;

    /**
     * Validate a user against the given credentials.
     *
     * @param array<string, mixed> $credentials
     */
    public function validateCredentials(AuthenticatableContract $user, array $credentials): bool;

    /**
     * Rehash the user's password if needed.
     *
     * This should be called after a successful login to ensure the password
     * uses the latest hashing algorithm options.
     *
     * @param array<string, mixed> $options
     */
    public function rehashPasswordIfRequired(AuthenticatableContract $user, string $password, array $options): void;
}
