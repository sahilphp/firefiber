<?php

declare(strict_types=1);

namespace Sahil\Foundation;

use Sahil\Contracts\Foundation\ApplicationContract;

/**
 * Base class for service providers.
 *
 * {@see register()} runs for every registered provider first (so bindings
 * can depend on each other freely), then {@see boot()} runs in registration
 * order once the container is fully known.
 *
 * Providers receive the application kernel through the constructor and can
 * therefore register bindings straight into the container.
 */
abstract class ServiceProvider
{
    public function __construct(private readonly ApplicationContract $app)
    {
    }

    protected function app(): ApplicationContract
    {
        return $this->app;
    }

    /**
     * Register bindings into the container.
     */
    public function register(): void
    {
    }

    /**
     * Perform post-registration bootstrapping, e.g. wiring events or config.
     */
    public function boot(): void
    {
    }
}
