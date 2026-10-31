<?php

declare(strict_types=1);

namespace Sahil\Auth;

use Sahil\Contracts\Auth\AuthenticatableContract;
use Sahil\Contracts\Auth\MustVerifyEmailContract;

/**
 * Email verification integration.
 *
 * Provides helper methods for checking and enforcing email verification.
 * Applications can use this to require email verification before
 * allowing access to certain features.
 */
final class EmailVerification
{
    /**
     * Check if a user has verified their email.
     */
    public function verified(AuthenticatableContract $user): bool
    {
        if (!$user instanceof MustVerifyEmailContract) {
            // User doesn't implement the contract, so we can't verify
            return true;
        }

        return $user->hasVerifiedEmail();
    }

    /**
     * Check if a user needs to verify their email.
     */
    public function needsVerification(AuthenticatableContract $user): bool
    {
        if (!$user instanceof MustVerifyEmailContract) {
            return false;
        }

        return !$user->hasVerifiedEmail();
    }

    /**
     * Send an email verification notification to the user.
     */
    public function sendVerification(AuthenticatableContract $user): void
    {
        if (!$user instanceof MustVerifyEmailContract) {
            return;
        }

        $user->sendEmailVerificationNotification();
    }

    /**
     * Mark a user's email as verified.
     */
    public function markAsVerified(AuthenticatableContract $user): void
    {
        if (!$user instanceof MustVerifyEmailContract) {
            return;
        }

        $user->markEmailAsVerified();
    }
}
