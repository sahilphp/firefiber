<?php

declare(strict_types=1);

namespace Sahil\Scheduler;

use RuntimeException;

/**
 * Raised for scheduler configuration errors: malformed cron expressions,
 * unknown timezones, duplicate task names, unusable lock storage.
 */
class SchedulerException extends RuntimeException
{
}
