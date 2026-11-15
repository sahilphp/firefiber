<?php

declare(strict_types=1);

namespace Sahil\Storage;

use RuntimeException;

/**
 * Raised when a client upload fails validation: malformed multipart,
 * oversized file, disallowed type, unsafe name...
 *
 * HTTP controllers should convert this into a 400 response.
 */
final class UploadException extends RuntimeException
{
}
