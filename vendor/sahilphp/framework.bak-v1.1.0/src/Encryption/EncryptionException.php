<?php

declare(strict_types=1);

namespace Sahil\Encryption;

use RuntimeException;

/**
 * Raised when a key is unusable, or a payload cannot be authenticated.
 *
 * Messages never contain key material, plaintext or ciphertext so they are
 * safe to log.
 */
final class EncryptionException extends RuntimeException
{
}
