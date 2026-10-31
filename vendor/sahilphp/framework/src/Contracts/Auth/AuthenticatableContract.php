<?php

declare(strict_types=1);

namespace Sahil\Contracts\Auth;

/**
 * Contract for authenticatable user models.
 *
 * Any user model that can be authenticated must implement this interface.
 * The framework uses this to retrieve user identifiers and authentication
 * credentials.
 */
interface AuthenticatableContract
{
    /**
     * Get the unique identifier for the user.
     *
     * @return int|string
     */
    public function getAuthIdentifier(): int|string;

    /**
     * Get the password for the user.
     *
     * This should return the hashed password, not the plaintext password.
     */
    public function getAuthPassword(): string;

    /**
     * Get the name of the unique identifier column.
     *
     * Used for database queries and session storage.
     */
    public function getAuthIdentifierName(): string;
}
