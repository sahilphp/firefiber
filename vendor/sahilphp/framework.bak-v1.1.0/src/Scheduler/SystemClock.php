<?php

declare(strict_types=1);

namespace Sahil\Scheduler;

use DateTimeImmutable;
use DateTimeZone;
use Sahil\Contracts\Scheduler\ClockContract;

/**
 * Wall-clock time from the system, normalised to UTC.
 */
final class SystemClock implements ClockContract
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
