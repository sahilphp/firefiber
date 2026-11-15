<?php

declare(strict_types=1);

namespace Sahil\Assets;

use RuntimeException;

/**
 * An asset cannot be resolved safely: unknown entry point, wrong profile,
 * invalid path, unsafe attribute or missing production build.
 */
final class AssetException extends RuntimeException
{
}
