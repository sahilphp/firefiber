<?php

declare(strict_types=1);

namespace Sahil\Encryption;

/**
 * Creates application keys in the format the Encrypter expects.
 *
 * The key is 32 cryptographically random bytes encoded as
 * `base64:<payload>`, which is the value written to `APP_KEY` in the
 * environment file.
 */
final class KeyGenerator
{
    /** Prefix that marks a base64-encoded key. */
    public const PREFIX = 'base64:';

    /**
     * Generate a new application key.
     */
    public static function generate(): string
    {
        return self::PREFIX . base64_encode(random_bytes(Encrypter::KEY_BYTES));
    }

    /**
     * Whether a configuration value can be used to build an Encrypter.
     */
    public static function isValid(string $key): bool
    {
        try {
            new Encrypter($key);

            return true;
        } catch (EncryptionException) {
            return false;
        }
    }
}
