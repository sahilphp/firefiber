<?php

declare(strict_types=1);

namespace Sahil\Auth;

/**
 * Password hashing and verification utilities.
 *
 * Uses PHP's built-in password API (password_hash, password_verify, etc.)
 * with secure defaults and configurable options.
 *
 * Security posture:
 * - Uses bcrypt algorithm by default (secure, widely tested)
 * - Configurable cost factor (default: 12)
 * - Automatic rehashing when algorithm options change
 * - Never logs passwords or exposes them in exceptions
 */
final class Password
{
    /**
     * Default hashing algorithm (bcrypt).
     */
    private const DEFAULT_ALGORITHM = PASSWORD_BCRYPT;

    /**
     * Default cost factor for bcrypt (12 is secure and performant).
     */
    private const DEFAULT_COST = 12;

    /**
     * Hash a password using the configured algorithm.
     *
     * @param array<string, mixed> $options hashing options (e.g., ['cost' => 12])
     */
    public static function hash(string $password, array $options = []): string
    {
        $algorithm = $options['algorithm'] ?? self::DEFAULT_ALGORITHM;
        $cost = $options['cost'] ?? self::DEFAULT_COST;

        $hashOptions = [
            'cost' => $cost,
        ];

        return password_hash($password, $algorithm, $hashOptions);
    }

    /**
     * Verify a password against a hash.
     */
    public static function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * Check if a password needs rehashing.
     *
     * This should be called after successful authentication to ensure
     * passwords are upgraded to the latest hashing options.
     *
     * @param array<string, mixed> $options current hashing options
     */
    public static function needsRehash(string $hash, array $options = []): bool
    {
        $algorithm = $options['algorithm'] ?? self::DEFAULT_ALGORITHM;
        $cost = $options['cost'] ?? self::DEFAULT_COST;

        $hashOptions = [
            'cost' => $cost,
        ];

        return password_needs_rehash($hash, $algorithm, $hashOptions);
    }

    /**
     * Get information about a password hash.
     *
     * Useful for debugging and verifying hash properties.
     *
     * @return array<string, mixed>
     */
    public static function getInfo(string $hash): array
    {
        return password_get_info($hash);
    }

    /**
     * Generate a secure random token for password resets, etc.
     *
     * @param int $length token length in bytes (default: 32 = 64 hex chars)
     */
    public static function generateToken(int $length = 32): string
    {
        return bin2hex(random_bytes($length));
    }
}
