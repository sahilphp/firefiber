<?php

declare(strict_types=1);

namespace Sahil\Queue\Jobs;

use Sahil\Contracts\Queue\DispatcherContract;
use Sahil\Contracts\Queue\Jobs\JobContract;

/**
 * Base class for queued work.
 *
 * ```php
 * final class SendWelcomeEmail extends Job
 * {
 *     public function __construct(private readonly string $email) {}
 *
 *     public function handle(): void
 *     {
 *         // send
 *     }
 * }
 *
 * (new SendWelcomeEmail('ada@example.com'))->dispatch();
 * ```
 *
 * A job is serialized whole, so it may only hold plain data.
 */
abstract class Job implements JobContract
{
    private ?string $queueName = null;

    public function queue(): ?string
    {
        return $this->queueName;
    }

    /**
     * Put the job on an explicitly named queue instead of the default.
     */
    public function onQueue(string $queue): static
    {
        $this->queueName = $queue;

        return $this;
    }

    public function maxAttempts(): ?int
    {
        return null;
    }

    public function retryDelay(int $attempt): ?int
    {
        return null;
    }

    /**
     * Queue the job and return the store id.
     */
    public function dispatch(int $delay = 0): string
    {
        return $this->dispatcher()->dispatch($this, $delay);
    }

    /**
     * Queue the job on a named queue and return the store id.
     */
    public function dispatchOn(string $queue, int $delay = 0): string
    {
        return $this->dispatcher()->pushOn($queue, $this, $delay);
    }

    private function dispatcher(): DispatcherContract
    {
        /** @var DispatcherContract $dispatcher */
        $dispatcher = app()->container()->make(DispatcherContract::class);

        return $dispatcher;
    }
}
