<?php

declare(strict_types=1);

namespace Sahil\Installer;

use RuntimeException;

/**
 * A project-creation operation could not continue.
 *
 * Messages are written for terminal users: one clear, actionable line
 * instead of a stack trace.
 */
class InstallerException extends RuntimeException
{
}
