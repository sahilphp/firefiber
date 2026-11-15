<?php

declare(strict_types=1);

namespace Sahil\Frontend;

use Sahil\Container\Container;
use Sahil\Contracts\Foundation\ApplicationContract;
use Sahil\Foundation\ServiceProvider;

/**
 * Optional core provider for frontend rendering.
 *
 * Applications (each JavaScript starter-kit) register it, then register
 * their own adapter in `boot()`:
 *
 *     $app->register(new FrontendServiceProvider($app));
 *     $app->container()->make(FrontendManager::class)->register(new VueAdapter(...));
 *
 * Configuration is read from the optional `frontend` namespace
 * (`config/frontend.php`); sensible defaults apply when it is absent.
 * Nothing here knows about Vue, React or Svelte.
 */
final class FrontendServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $container = $this->app()->container();

        $container->singleton(FrontendManager::class, static fn (): FrontendManager => new FrontendManager());

        $container->singleton(FrontendConfiguration::class, static function (Container $container): FrontendConfiguration {
            /** @var array<string, mixed> $configuration */
            $configuration = (array) $container->make(ApplicationContract::class)
                ->config()
                ->get('frontend', []);

            return FrontendConfiguration::fromArray($configuration);
        });

        $container->singleton(FrontendRenderer::class, static fn (Container $container): FrontendRenderer
            => new FrontendRenderer(
                $container->make(FrontendManager::class),
                $container->make(FrontendConfiguration::class),
            ));
    }
}
