<?php

declare(strict_types=1);

namespace Sahil\Storage;

use RuntimeException;

/**
 * Raised for storage failures: unsafe paths, missing files, permission
 * problems, unsupported drivers... Deliberately NOT part of the HTTP
 * exception tree: storage errors are operational failures the operator
 * must fix, and never render as HTTP status codes directly.
 */
final class StorageException extends RuntimeException
{
}
