<?php

declare(strict_types=1);

namespace Sahil\Firefiber\Assets;

use RuntimeException;

/**
 * Raised when a catalogued Firefiber asset exists in the allow-list but its
 * file cannot be read from the installed package.
 *
 * The message is deliberately suitable for logs only. HTTP responses expose
 * a generic error so filesystem paths never reach a browser.
 */
final class AssetUnavailableException extends RuntimeException
{
}
