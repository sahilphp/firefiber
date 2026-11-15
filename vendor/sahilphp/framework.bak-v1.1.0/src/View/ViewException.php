<?php

declare(strict_types=1);

namespace Sahil\View;

use RuntimeException;

/**
 * Base class for every view/template failure.
 *
 * Catching this type is enough to handle "the template layer went wrong"
 * without swallowing unrelated application errors.
 */
class ViewException extends RuntimeException
{
}
