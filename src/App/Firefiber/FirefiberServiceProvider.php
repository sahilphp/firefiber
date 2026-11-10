<?php

declare(strict_types=1);

namespace Sahil\Firefiber;

use Sahil\Container\Container;
use Sahil\Contracts\Assets\AssetManagerContract;
use Sahil\Contracts\Cache\CacheContract;
use Sahil\Contracts\Foundation\ApplicationContract;
use Sahil\Contracts\Session\SessionContract;
use Sahil\Contracts\Storage\StorageContract;
use Sahil\Contracts\View\ViewContract;
use Sahil\Firefiber\Assets\AssetRepository;
use Sahil\Firefiber\Assets\FirefiberAssetManager;
use Sahil\Firefiber\Assets\RuntimeAssets;
use Sahil\Firefiber\Http\AssetController;
use Sahil\Firefiber\Http\FirefiberActionController;
use Sahil\Firefiber\Http\FirefiberMiddleware;
use Sahil\Firefiber\Storage\FileCacheReplayStorage;
use Sahil\Firefiber\Storage\FileCacheStateStorage;
use Sahil\Firefiber\Storage\ReplayStorageInterface;
use Sahil\Firefiber\Storage\StateStorageInterface;
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
 * The provider also registers the secure action endpoint, discovers
 * conventional application pages, and renders their components.
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
        $application = $this->app();
        $config = $application->config();

        // The action endpoint uses Firefiber's signed snapshot protocol and
        // its own CSRF validation. Explicitly exempt only that endpoint from
        // the framework's token-rotating CSRF middleware, whose generated
        // token would otherwise change after every reactive action.
        $actionPath = self::actionPath($application);
        $csrfExcept = (array) $config->get('session.csrf_except', []);

        foreach ([$actionPath, self::uploadPath($application)] as $exemptPath) {
            if (!in_array($exemptPath, $csrfExcept, true)) {
                $csrfExcept[] = $exemptPath;
            }
        }

        $config->set('session.csrf_except', $csrfExcept);

        $container->singleton(
            ReplayStorageInterface::class,
            static function (Container $container): ReplayStorageInterface {
                $application = $container->make(ApplicationContract::class);
                $configured = $application->config()->get('firefiber.replay_cache_path');
                $path = is_string($configured) && $configured !== ''
                    ? $configured
                    : $application->basePath('storage/framework/firefiber/replay');

                return new FileCacheReplayStorage($path);
            },
        );

        $container->singleton(
            StateStorageInterface::class,
            static function (Container $container): StateStorageInterface {
                $application = $container->make(ApplicationContract::class);
                $configured = $application->config()->get('firefiber.state_cache_path');
                $path = is_string($configured) && $configured !== ''
                    ? $configured
                    : $application->basePath('storage/framework/firefiber/state');

                return new FileCacheStateStorage($path);
            },
        );

        $container->singleton(
            SnapshotIntegrity::class,
            static function (Container $container): SnapshotIntegrity {
                $application = $container->make(ApplicationContract::class);
                $expiration = $application->config()->get('firefiber.snapshot_expiration_seconds', 300);

                return new SnapshotIntegrity(
                    FirefiberSecrets::masterKey($application),
                    is_int($expiration) ? $expiration : (is_numeric($expiration) ? (int) $expiration : 300),
                );
            },
        );

        $container->singleton(
            FirefiberMiddleware::class,
            static function (Container $container): FirefiberMiddleware {
                $application = $container->make(ApplicationContract::class);
                $session = $container->bound(SessionContract::class)
                    ? $container->make(SessionContract::class)
                    : null;
                $storage = $container->bound(StorageContract::class)
                    ? $container->make(StorageContract::class)
                    : null;
                $expiration = $application->config()->get('firefiber.snapshot_expiration_seconds', 300);
                $ttl = $application->config()->get('firefiber.state_ttl_seconds', 0);
                $lockPath = $application->config()->get('firefiber.lock_cache_path');
                $lockPath = is_string($lockPath) && $lockPath !== ''
                    ? $lockPath
                    : $application->basePath('storage/framework/firefiber/locks');

                return new FirefiberMiddleware(
                    $container->make(ReplayStorageInterface::class),
                    null,
                    is_int($expiration) ? $expiration : (is_numeric($expiration) ? (int) $expiration : 300),
                    null,
                    $container->make(StateStorageInterface::class),
                    null,
                    is_int($ttl) ? $ttl : (is_numeric($ttl) ? (int) $ttl : 0),
                    $application,
                    $session,
                    null,
                    $lockPath,
                    $storage,
                );
            },
        );

        $container->alias('firefiber.middleware', FirefiberMiddleware::class);

        $container->singleton(
            ComponentParentRegistry::class,
            static fn (Container $container): ComponentParentRegistry => new ComponentParentRegistry(
                $container->make(StateStorageInterface::class),
            ),
        );

        $container->singleton(
            ComponentCache::class,
            static fn (Container $container) => new ComponentCache(
                $container->bound(CacheContract::class) ? $container->make(CacheContract::class) : null,
                $container->bound(StorageContract::class) ? $container->make(StorageContract::class) : null,
            ),
        );

        $container->singleton(
            PageRenderer::class,
            static function (Container $container): PageRenderer {
                $views = $container->bound(ViewContract::class)
                    ? $container->make(ViewContract::class)
                    : null;
                $session = $container->bound(SessionContract::class)
                    ? $container->make(SessionContract::class)
                    : null;
                $cache = $container->bound(CacheContract::class)
                    ? $container->make(CacheContract::class)
                    : null;

                return new PageRenderer(
                    $container->make(ApplicationContract::class),
                    $container->make(StateStorageInterface::class),
                    $container->make(SnapshotIntegrity::class),
                    $views,
                    $session,
                    null,
                    $container->make(ComponentParentRegistry::class),
                    $cache,
                );
            },
        );

        $container->singleton(
            PageRegistry::class,
            static fn (Container $container): PageRegistry => new PageRegistry(
                $container->make(ApplicationContract::class),
            ),
        );

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

        $router = $container->make(Router::class);
        self::registerRoutes(
            $router,
            $container->make(AssetRepository::class),
            self::actionPath($this->app()),
            self::uploadPath($this->app()),
        );

        if ($container->bound(PageRegistry::class)) {
            $container->make(PageRegistry::class)->registerRoutes();
        }
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
    public static function registerRoutes(Router $router, ?AssetRepository $assets = null, string $actionPath = '/firefiber', string $uploadPath = '/firefiber/uploads'): void
    {
        $assets ??= new AssetRepository();

        if ($router->routeByName(AssetRepository::ROUTE_NAME) === null) {
            $router->register(
                Route::get($assets->routePath(), [AssetController::class, 'show'])
                    ->name(AssetRepository::ROUTE_NAME),
            );
        }

        if ($router->routeByName('firefiber.action') === null) {
            $router->register(
                Route::post($actionPath, [FirefiberActionController::class, 'handle'])
                    ->middleware(FirefiberMiddleware::class)
                    ->name('firefiber.action'),
            );
        }

        if ($router->routeByName('firefiber.uploads') === null) {
            $router->register(
                Route::post($uploadPath, [FirefiberActionController::class, 'upload'])
                    ->middleware(FirefiberMiddleware::class)
                    ->name('firefiber.uploads'),
            );
        }
    }

    public static function uploadPath(ApplicationContract $application): string
    {
        $path = $application->config()->get('firefiber.upload_path', '/firefiber/uploads');

        return is_string($path) && $path !== '' && str_starts_with($path, '/') ? $path : '/firefiber/uploads';
    }

    private static function actionPath(ApplicationContract $application): string
    {
        $path = $application->config()->get('firefiber.action_path', '/firefiber');

        return is_string($path) && $path !== '' && str_starts_with($path, '/') ? $path : '/firefiber';
    }

    /**
     * Resolve the middleware instance from the container.
     */
    public static function middleware(): FirefiberMiddleware
    {
        return app()->container()->make(FirefiberMiddleware::class);
    }
}
