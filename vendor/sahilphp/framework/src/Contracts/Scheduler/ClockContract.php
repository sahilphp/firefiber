<?php

declare(strict_types=1);

namespace Sahil\Contracts\Scheduler;

use DateTimeImmutable;

/**
 * The only source of "now" for scheduling decisions.
 *
 * Injecting the clock keeps due/next-run logic deterministic in tests:
 * production binds {@see \Sahil\Scheduler\SystemClock}, tests bind a fixed
 * instant.
 */
interface ClockContract
{
    /**
     * Current instant, always in UTC. Consumers convert to the timezone
     * that matters to them (a task's timezone, for instance).
     */
    public function now(): DateTimeImmutable;
}
