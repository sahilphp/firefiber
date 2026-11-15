<?php

declare(strict_types=1);

namespace Sahil\Container;

use Psr\Container\ContainerExceptionInterface;

/**
 * Thrown when a binding cannot be resolved.
 */
class BindingException extends \RuntimeException implements ContainerExceptionInterface
{
}
