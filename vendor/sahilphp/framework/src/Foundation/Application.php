<?php

declare(strict_types=1);

namespace Sahil\Foundation;

use RuntimeException;
use Sahil\Config\Repository;
use Sahil\Container\Container;
use Sahil\Contracts\Foundation\ApplicationContract;
use Sahil\Support\Environment;
use Throwable;

/**
 * Application kernel.
 *
 * Owns the DI container, the environment and the configuration repository,
 * and drives the service provider lifecycle: every provider `register`s
 * first, then every provider `boot`s in registration order.
 *
 * The class keeps no global static application state beyond the
 * process-configured instance handle; long-running workers keep one
 * Application per process and call {@see Application::reset()} between
 * iterations instead of relying on process termination to clean up.
 */
final class Application implements ApplicationContract
{
    private static ?Application $configured = null;

    private Container $container;

    private Environment $environment;

    private Repository $config;

    /** @var list<ServiceProvider> */
    private array $providers = [];

    private bool $booted = false;

    private function __construct(private readonly string $basePath)
    {
        $this->container = new Container();
        $this->environment = new Environment(getenv() ?: []);
        $this->config = new Repository();

        $this->installCoreBindings();
    }

    /**
     * Create an application rooted at the given directory.
     *
     * Throws if an application is already configured for this process;
     * that configuration is intentional and must be released with
     * {@see free()} or cycled with {@see reset()} instead of being replaced.
     */
    public static function configure(string $basePath): static
    {
        if (self::$configured !== null) {
            throw new RuntimeException(
                'An Application is already configured for this process. Use free()/reset() to cycle it.',
            );
        }

        $basePath = self::normalizePath($basePath);

        if (!is_dir($basePath)) {
            throw new RuntimeException(sprintf('Application base path [%s] does not exist.', $basePath));
        }

        return self::$configured = new static($basePath);
    }

    /**
     * Absolute framework base path, optionally with a sub-path joined on.
     */
    public function basePath(string $sub = ''): string
    {
        return $sub === '' ? $this->basePath : $this->basePath.'/'.trim($sub, '/');
    }

    public function environmentPath(string $filename = '.env'): string
    {
        return $this->basePath($filename);
    }

    public function configPath(string $sub = ''): string
    {
        return $this->basePath('config'.($sub === '' ? '' : '/'.trim($sub, '/')));
    }

    public function storagePath(string $sub = ''): string
    {
        return $this->basePath('storage'.($sub === '' ? '' : '/'.trim($sub, '/')));
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function environment(): Environment
    {
        return $this->environment;
    }

    public function config(): Repository
    {
        return $this->config;
    }

    public function isBooted(): bool
    {
        return $this->booted;
    }

    /**
     * Load a `.env` file relative to the base path. A missing file is not
     * an error (ail real deployments usually configure via OS environment);
     * an unreadable or malformed file is.
     */
    public function loadEnvironment(string $filename = '.env'): static
    {
        $path = $this->environmentPath($filename);

        if (is_file($path)) {
            $this->environment->load($path);
        }

        return $this;
    }

    /**
     * Load every PHP file in the config directory that returns an array
     * tree. Files are keyed by basename; e.g. `config/app.php` populates
     * the `app.*` namespace.
     */
    public function loadConfiguration(string $directory = ''): static
    {
        $directoryPath = $this->configPath($directory);

        if (!is_dir($directoryPath)) {
            throw new RuntimeException(sprintf('Configuration directory [%s] does not exist.', $directoryPath));
        }

        $files = glob($directoryPath.'/*.php');

        if ($files === false) {
            throw new RuntimeException(sprintf('Unable to scan configuration directory [%s].', $directoryPath));
        }

        $loaded = [];

        $environmentNames = ['development', 'testing', 'staging', 'production'];

        foreach ($files as $file) {
            // File names matching the closed environment list are overlays
            // (config/{environment}.php), not standalone config namespaces.
            if (in_array(basename($file, '.php'), $environmentNames, true)) {
                continue;
            }

            try {
                /** @var mixed $tree */
                $tree = require $file;
            } catch (Throwable $throwable) {
                throw new RuntimeException(
                    sprintf('Config file [%s] failed to load: %s', $file, $throwable->getMessage()),
                    previous: $throwable,
                );
            }

            if (!is_array($tree)) {
                throw new RuntimeException(
                    sprintf('Config file [%s] must return an array, got %s.', $file, get_debug_type($tree)),
                );
            }

            $loaded[basename($file, '.php')] = $tree;
        }

        $this->config->replace($loaded);

        $this->loadEnvironmentOverlay();
        $this->validateConfiguration();

        return $this;
    }

    /**
     * Environment-specific configuration overlay: after the base config
     * files load, `config/{environment}.php` (e.g. `config/production.php`
     * or `config/development.php`) is deep-merged on top when it exists.
     * A missing overlay is normal; a broken overlay file is a startup error.
     */
    private function loadEnvironmentOverlay(): void
    {
        $environment = $this->config->get('app.environment');

        if (!is_string($environment) || $environment === '') {
            return; // No environment specified: nothing to overlay.
        }

        $overlayPath = $this->configPath($environment.'.php');

        if (!is_file($overlayPath)) {
            return;
        }

        try {
            /** @var mixed $tree */
            $tree = require $overlayPath;
        } catch (Throwable $throwable) {
            throw new \Sahil\Config\ConfigurationException(
                sprintf('Environment config [%s] failed to load: %s', $overlayPath, $throwable->getMessage()),
                previous: $throwable,
            );
        }

        if (!is_array($tree)) {
            throw new \Sahil\Config\ConfigurationException(
                sprintf('Environment config [%s] must return an array, got %s.', $overlayPath, get_debug_type($tree)),
            );
        }

        $this->config->merge($tree);
    }

    /**
     * Startup-time configuration validation. Checks what is present:
     *
     * - `app.environment` (when set) ∈ the known environment list,
     * - `app.debug` (when set) must be a boolean,
     * - `app.debug=true` + `app.environment=production` is rejected —
     *   production must never boot with request diagnostics visible,
     * = `app.timezone` (when set) must be a valid timezone identifier,
     * - `app.log_level` (when set) must be a known severity.
     *
     * Every violation is reported in a single
     * {@see \Sahil\Config\ConfigurationException} so boot fails with the
     * full picture instead of one problem at a time.
     */
    private function validateConfiguration(): void
    {
        $violations = [];

        $environment = $this->config->get('app.environment');

        if ($environment !== null) {
            if (!is_string($environment)) {
                $violations[] = sprintf('app.environment must be a string, got %s.', get_debug_type($environment));
            } elseif (!in_array($environment, ['development', 'testing', 'staging', 'production'], true)) {
                $violations[] = sprintf(
                    'app.environment [%s] is not one of: development, testing, staging, production.',
                    $environment,
                );
            }
        }

        $debug = $this->config->get('app.debug');

        if ($debug !== null && !is_bool($debug)) {
            $violations[] = sprintf('app.debug must be a boolean, got %s.', get_debug_type($debug));
        }

        if ($debug === true && $environment === 'production') {
            $violations[] = 'app.debug must be false in production; diagnostics must not reach clients.';
        }

        $timezone = $this->config->get('app.timezone');

        if ($timezone !== null && (!is_string($timezone) || @timezone_open($timezone) === false)) {
            $violations[] = sprintf('app.timezone [%s] is not a valid timezone identifier.', is_string($timezone) ? $timezone : get_debug_type($timezone));
        }

        $logLevel = $this->config->get('app.log_level');

        if ($logLevel !== null && (!is_string($logLevel) || !in_array($logLevel, \Sahil\Support\Logging\FileLogger::severityList(), true))) {
            $violations[] = sprintf(
                'app.log_level must be one of: %s.',
                implode(', ', \Sahil\Support\Logging\FileLogger::severityList()),
            );
        }

        if ($violations !== []) {
            throw new \Sahil\Config\ConfigurationException(
                "Configuration validation failed:\n- ".implode("\n- ", $violations),
            );
        }
    }

    /**
     * Register a bootstrapping provider instance. `register()` runs
     * immediately so later providers can depend on earlier bindings.
     */
    public function register(ServiceProvider $provider): static
    {
        $this->providers[] = $provider;
        $provider->register();

        return $this;
    }

    /**
     * Run the whole provider boot sequence. Calling it more than once is
     * a no-op so multiple lifecycle callers cannot boot twice.
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        foreach ($this->providers as $provider) {
            $provider->boot();
        }
    }

    /**
     * Open a request scope and run `$work` inside it. The scope always
     * ends in a `finally` block — on success and on error — so scoped
     * instances can never leak into the next request.
     */
    public function isolatedRequest(callable $work): mixed
    {
        $scope = $this->container->beginScope();

        try {
            return $work($scope);
        } finally {
            $scope->end();
        }
    }

    /**
     * Clear all request-scoped state so the same process can serve another
     * iteration: the container is flushed, core bindings re-instated and
     * providers are re-registered and re-booted in order.
     *
     * Providers used with long-running workers must therefore be idempotent
     * and must not hold request state at the provider level.
     */
    public function reset(): void
    {
        $this->container->flush();
        $this->installCoreBindings();

        foreach ($this->providers as $provider) {
            $provider->register();
            $provider->boot();
        }
    }

    /**
     * The process-configured application, if any.
     */
    public static function peek(): ?static
    {
        return self::$configured;
    }

    /**
     * Release the process-configured application handle, e.g. in tests or
     * when spawning worker children.
     */
    public static function free(): void
    {
        self::$configured = null;
    }

    private function installCoreBindings(): void
    {
        $this->container->instance(Application::class, $this);
        $this->container->instance(Container::class, $this->container);
        $this->container->instance(Environment::class, $this->environment);
        $this->container->instance(Repository::class, $this->config);
        $this->container->instance(ApplicationContract::class, $this);

        $this->container->alias('app', Application::class);
        $this->container->alias('env', Environment::class);
        $this->container->alias('config', Repository::class);
    }

    private static function normalizePath(string $path): string
    {
        $real = realpath($path);

        if ($real === false) {
            throw new RuntimeException(sprintf('Application base path [%s] could not be resolved.', $path));
        }

        return $real;
    }
}
