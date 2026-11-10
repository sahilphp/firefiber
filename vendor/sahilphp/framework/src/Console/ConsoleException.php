<?php

declare(strict_types=1);

namespace Sahil\Console;

use RuntimeException;

/**
 * A user-facing console error.
 *
 * The dispatcher prints these as a single `command: message` line and exits
 * with a non-zero status; unexpected throwables are reported the same way but
 * never include a stack trace unless SAHIL_DEBUG=1 is set.
 */
final class ConsoleException extends RuntimeException
{
}
