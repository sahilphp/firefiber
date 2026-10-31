<?php

declare(strict_types=1);

namespace Sahil\Encryption;

/**
 * Authenticated symmetric encryption and HMAC signing for one application key.
 *
 * Design:
 * - AES-256-GCM through `openssl_encrypt()`: confidentiality plus integrity in
 *   one pass, so tampering is detected on decrypt instead of being returned.
 * - A fresh 12-byte random IV per message; the IV and the 16-byte tag travel
 *   with the ciphertext inside one base64 payload.
 * - `sign()`/`verify()` expose a deterministic HMAC-SHA-256 for cookies, URLs
 *   and other values that must be tamper-evident but readable.
 * - Key material is accepted as `base64:<value>` (what `APP_KEY` holds) or as
 *   32 raw bytes; anything else is rejected before any crypto runs.
 *
 * Nothing here degrades silently: an unusable key, a malformed payload or a
 * failed authentication raises EncryptionException.
 */
final class Encrypter
{
    /** OpenSSL cipher name. */
    public const CIPHER = 'aes-256-gcm';

    /** Key length in raw bytes (AES-256). */
    public const KEY_BYTES = 32;

    /** Prefix that marks a base64-encoded key. */
    public const KEY_PREFIX = 'base64:';

    /** GCM nonce length recommended for AES-GCM. */
    private const IV_BYTES = 12;

    /** GCM authentication tag length. */
    private const TAG_BYTES = 16;

    /** Raw 32-byte key. */
    private readonly string $key;

    /**
     * @param string $key `base64:<payload>` or exactly 32 raw bytes
     */
    public function __construct(#[\SensitiveParameter] string $key)
    {
        if (str_starts_with($key, self::KEY_PREFIX)) {
            $encoded = substr($key, strlen(self::KEY_PREFIX));
            $decoded = base64_decode($encoded, true);

            if ($decoded === false) {
                throw new EncryptionException('The application key is not valid base64.');
            }

            $key = $decoded;
        }

        if (strlen($key) !== self::KEY_BYTES) {
            throw new EncryptionException(sprintf(
                'The application key must be %d raw bytes (base64-encoded or as "%s<base64>").',
                self::KEY_BYTES,
                self::KEY_PREFIX,
            ));
        }

        $this->key = $key;
    }

    /**
     * Build an Encrypter from a configured value, refusing to run without one.
     */
    public static function fromConfiguration(?string $key): self
    {
        if ($key === null || trim($key) === '') {
            throw new EncryptionException(
                'No application key is configured. Set APP_KEY (run `bin/sahil key:generate`).',
            );
        }

        return new self(trim($key));
    }

    /**
     * Encrypt a string, returning a base64 payload of IV + tag + ciphertext.
     */
    public function encryptString(#[\SensitiveParameter] string $plaintext): string
    {
        $iv = random_bytes(self::IV_BYTES);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_BYTES,
        );

        if ($ciphertext === false) {
            throw new EncryptionException('Encryption failed.');
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Decrypt a payload produced by {@see self::encryptString()}.
     *
     * @throws EncryptionException when the payload is malformed or does not authenticate
     */
    public function decryptString(#[\SensitiveParameter] string $payload): string
    {
        $raw = base64_decode($payload, true);

        if ($raw === false || strlen($raw) < self::IV_BYTES + self::TAG_BYTES) {
            throw new EncryptionException('The encrypted payload is malformed.');
        }

        $iv = substr($raw, 0, self::IV_BYTES);
        $tag = substr($raw, self::IV_BYTES, self::TAG_BYTES);
        $ciphertext = substr($raw, self::IV_BYTES + self::TAG_BYTES);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
        );

        if ($plaintext === false) {
            throw new EncryptionException('The payload could not be decrypted: wrong key or tampered data.');
        }

        return $plaintext;
    }

    /**
     * Sign a value with HMAC-SHA-256.
     */
    public function sign(string $value): string
    {
        return hash_hmac('sha256', $value, $this->key);
    }

    /**
     * Constant-time verification of a signature produced by {@see self::sign()}.
     */
    public function verify(string $value, string $signature): bool
    {
        return hash_equals($this->sign($value), $signature);
    }
}
