<?php

declare(strict_types=1);

namespace Sahil\Auth;

use Sahil\Contracts\Auth\AuthenticatableContract;
use Sahil\Contracts\Auth\PasswordResetTokenRepositoryContract;
use Sahil\Contracts\Auth\UserProviderContract;

/**
 * Password reset manager.
 *
 * Handles password reset token generation, validation, and user password updates.
 *
 * Security features:
 * - Secure random tokens (64 hex characters)
 * - Token expiration (configurable, default 1 hour)
 * - Single-use tokens (deleted after use)
 * - No user enumeration (generic responses)
 * - Token storage interface for flexibility
 */
final class PasswordResetManager
{
    private const TOKEN_LENGTH = 32; // 64 hex characters

    private const DEFAULT_EXPIRATION_MINUTES = 60;

    public function __construct(
        private readonly UserProviderContract $provider,
        private readonly PasswordResetTokenRepositoryContract $tokenRepository,
        private readonly int $expirationMinutes = self::DEFAULT_EXPIRATION_MINUTES,
    ) {
    }

    /**
     * Create a password reset token for a user.
     *
     * Returns the token if successful, null if user not found.
     * Does not reveal whether the user exists (prevents enumeration).
     */
    public function createToken(string $email): ?string
    {
        $user = $this->provider->retrieveByCredentials(['email' => $email]);

        if ($user === null) {
            // Return null but don't reveal that user doesn't exist
            return null;
        }

        $token = Password::generateToken(self::TOKEN_LENGTH);

        $this->tokenRepository->create(
            $user->getAuthIdentifier(),
            $token,
            $this->expirationMinutes,
        );

        return $token;
    }

    /**
     * Validate a password reset token.
     *
     * Returns the user if the token is valid, null otherwise.
     */
    public function validateToken(string $token, string $email): ?AuthenticatableContract
    {
        $user = $this->provider->retrieveByCredentials(['email' => $email]);

        if ($user === null) {
            return null;
        }

        $record = $this->tokenRepository->find($user->getAuthIdentifier(), $token);

        if ($record === null) {
            return null;
        }

        if ($this->tokenRepository->isExpired($record)) {
            return null;
        }

        return $user;
    }

    /**
     * Reset a user's password using a valid token.
     *
     * Returns true if successful, false otherwise.
     */
    public function resetPassword(string $token, string $email, string $newPassword): bool
    {
        $user = $this->validateToken($token, $email);

        if ($user === null) {
            return false;
        }

        // Delete the token (single-use)
        $this->tokenRepository->delete($user->getAuthIdentifier(), $token);

        // Update the user's password
        // This requires the user provider to support password updates
        // In a real implementation, this would be done via a repository
        // For now, we'll assume the provider has a method for this
        if (method_exists($this->provider, 'updatePassword')) {
            $this->provider->updatePassword($user, $newPassword);

            return true;
        }

        return false;
    }

    /**
     * Delete all password reset tokens for a user.
     */
    public function deleteTokens(AuthenticatableContract $user): void
    {
        $this->tokenRepository->deleteUserTokens($user->getAuthIdentifier());
    }
}
