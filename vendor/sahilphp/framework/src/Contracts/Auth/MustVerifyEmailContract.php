<?php

declare(strict_types=1);

namespace Sahil\Contracts\Auth;

/**
 * Contract for users that must verify their email address.
 *
 * Users implementing this interface can be required to verify their email
 * before accessing certain features of the application.
 */
interface MustVerifyEmailContract
{
    /**
     * Determine if the user has verified their email.
     */
    public function hasVerifiedEmail(): bool;

    /**
     * Mark the user's email as verified.
     */
    public function markEmailAsVerified(): void;

    /**
     * Send an email verification notification.
     */
    public function sendEmailVerificationNotification(): void;

    /**
     * Get the email address that should be verified.
     */
    public function getEmailForVerification(): string;
}
