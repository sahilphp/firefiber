<?php

declare(strict_types=1);

namespace Sahil\Firefiber;

use Sahil\Container\Container;
use Sahil\Contracts\Assets\AssetManagerContract;
use Sahil\Contracts\Foundation\ApplicationContract;
use Sahil\Firefiber\Assets\AssetRepository;
use Sahil\Firefiber\Assets\FirefiberAssetManager;
use Sahil\Firefiber\Assets\RuntimeAssets;
use Sahil\Firefiber\Http\AssetController;
use Sahil\Firefiber\Http\FirefiberMiddleware;
use Sahil\Foundation\ServiceProvider;
use Sahil\Http\Route;
use Sahil\Http\Router;

/**
 * Registers Firefiber's request middleware and package-managed browser
 * assets with a SahilPHP application.
 *
 * Register this provider in the application's `bootstrap/providers.php`.
 * The framework has no Composer auto-discovery mechanism, so no package can
 * silently add a provider or an asset route to an application.
 *
 * Assets are served from the installed package through a fixed route. No
 * Composer install script and no provider boot step copies files into the
 * consumer's `public/`, `resources/` or any other application directory.
 */
class FirefiberServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $container = $this->app()->container();

        $container->singleton(
            FirefiberMiddleware::class,
            static fn (): FirefiberMiddleware => new FirefiberMiddleware(),
        );

        $container->alias('firefiber.middleware', FirefiberMiddleware::class);

        $container->singleton(
            AssetRepository::class,
            static function (Container $container): AssetRepository {
                $application = $container->make(ApplicationContract::class);
                $prefix = $application->config()->get(
                    'firefiber.assets.public_prefix',
                    AssetRepository::DEFAULT_PUBLIC_PREFIX,
                );

                return new AssetRepository(
                    AssetRepository::defaultPackageRoot(),
                    is_string($prefix) ? $prefix : AssetRepository::DEFAULT_PUBLIC_PREFIX,
                );
            },
        );

        $container->alias('firefiber.assets', AssetRepository::class);

        $container->singleton(
            RuntimeAssets::class,
            static function (Container $container): RuntimeAssets {
                $application = $container->make(ApplicationContract::class);
                $config = $application->config();
                $development = $config->get('firefiber.assets.development', null);

                if (!is_bool($development)) {
                    $development = $config->get('app.environment', 'production') !== 'production';
                }

                return new RuntimeAssets(
                    $container->make(AssetRepository::class),
                    $development,
                );
            },
        );
    }

    public function boot(): void
    {
        $container = $this->app()->container();

        self::registerAssetAliases($container);

        if (!$container->bound(Router::class)) {
            // CLI/minimal applications may not install an HTTP router. The
            // asset service remains available; only URL delivery is skipped.
            return;
        }

        self::registerRoutes($container->make(Router::class), $container->make(AssetRepository::class));
    }

    /**
     * Register Firefiber's aliases with the existing application asset
     * pipeline.
     *
     * Boot runs after every provider's register() phase, so the application
     * AssetManagerContract is already bound (when the application uses the
     * standard asset provider). The decorator delegates every non-Firefiber
     * entry to that manager, preserving application aliases and frontend
     * tooling.
     */
    public static function registerAssetAliases(Container $container): FirefiberAssetManager
    {
        $existing = $container->bound(AssetManagerContract::class)
            ? $container->make(AssetManagerContract::class)
            : null;

        if ($existing instanceof FirefiberAssetManager) {
            $manager = $existing;
        } else {
            $manager = new FirefiberAssetManager(
                $container->make(AssetRepository::class),
                $existing instanceof AssetManagerContract ? $existing : null,
            );
        }

        $container->instance(AssetManagerContract::class, $manager);
        $container->instance(FirefiberAssetManager::class, $manager);
        $container->alias('assets', AssetManagerContract::class);
        $container->alias('firefiber.assets.manager', FirefiberAssetManager::class);

        return $manager;
    }

    /**
     * Register the asset route idempotently. Exposed so applications with a
     * custom route table can opt in without booting the provider.
     */
    public static function registerRoutes(Router $router, ?AssetRepository $assets = null): void
    {
        if ($router->routeByName(AssetRepository::ROUTE_NAME) !== null) {
            return;
        }

        $assets ??= new AssetRepository();

        $router->register(
            Route::get($assets->routePath(), [AssetController::class, 'show'])
                ->name(AssetRepository::ROUTE_NAME),
        );
    }

    /**
     * Resolve the middleware instance from the container.
     */
    public static function middleware(): FirefiberMiddleware
    {
        return app()->container()->make(FirefiberMiddleware::class);
    }
}
