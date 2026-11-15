<?php

declare(strict_types=1);

namespace Sahil\Ui;

use RuntimeException;

/**
 * A UI installation, build or status operation could not continue.
 *
 * Messages are written for terminal users: one clear, actionable line
 * instead of a stack trace.
 */
class UiException extends RuntimeException
{
}
