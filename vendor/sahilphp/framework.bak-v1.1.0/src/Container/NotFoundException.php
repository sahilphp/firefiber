<?php

declare(strict_types=1);

namespace Sahil\Container;

use Psr\Container\NotFoundExceptionInterface;

/**
 * Thrown when an identifier has no binding, instance or auto-wirable class.
 */
class NotFoundException extends \OutOfBoundsException implements NotFoundExceptionInterface
{
}
