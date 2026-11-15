<?php

declare(strict_types=1);

namespace Sahil\Notifications;

use RuntimeException;

/**
 * Notification wiring problem: an unknown channel, or a mail channel given a
 * notification that cannot compose mail. These are programming errors, not
 * delivery failures; delivery failures are reported in a
 * {@see NotificationReport} instead.
 */
final class NotificationException extends RuntimeException
{
}
