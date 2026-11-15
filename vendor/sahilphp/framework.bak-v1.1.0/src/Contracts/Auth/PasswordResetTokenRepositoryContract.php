<?php

declare(strict_types=1);

namespace Sahil\Contracts\Auth;

/**
 * Contract for password reset token storage.
 *
 * Allows applications to implement their own token storage
 * (database, cache, Redis, etc.).
 */
interface PasswordResetTokenRepositoryContract
{
    /**
     * Create a password reset token.
     *
     * @param int|string $userId
     */
    public function create(int|string $userId, string $token, int $expirationMinutes): void;

    /**
     * Find a token record by user ID and token.
     *
     * @param int|string $userId
     * @return array{created_at: int}|null
     */
    public function find(int|string $userId, string $token): ?array;

    /**
     * Check if a token record is expired.
     *
     * @param array{created_at: int} $record
     */
    public function isExpired(array $record): bool;

    /**
     * Delete a specific token.
     *
     * @param int|string $userId
     */
    public function delete(int|string $userId, string $token): void;

    /**
     * Delete all tokens for a user.
     *
     * @param int|string $userId
     */
    public function deleteUserTokens(int|string $userId): void;

    /**
     * Delete expired tokens.
     */
    public function deleteExpired(): void;
}
