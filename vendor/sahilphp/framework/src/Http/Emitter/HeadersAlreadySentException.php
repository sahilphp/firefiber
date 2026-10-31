<?php

declare(strict_types=1);

namespace Sahil\Http\Emitter;

use RuntimeException;

/**
 * Thrown when a Response is emitted after output already reached the
 * client: the response can no longer be delivered as intended.
 */
final class HeadersAlreadySentException extends RuntimeException
{
}
