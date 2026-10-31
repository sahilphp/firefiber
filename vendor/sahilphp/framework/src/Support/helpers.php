<?php

declare(strict_types=1);

use Sahil\Config\Repository;
use Sahil\Foundation\Application;
use Sahil\Support\Environment;

if (!function_exists('app')) {
    /**
     * Resolve the configured application, or a service from its container.
     */
    function app(): Application
    {
        $application = Application::peek();

        if ($application === null) {
            throw new RuntimeException('No Application has been configured for this process yet.');
        }

        return $application;
    }
}

if (!function_exists('config')) {
    /**
     * Read a config value from the configured application's repository.
     */
    function config(string $key, mixed $default = null): mixed
    {
        /** @var Repository|null $repository */
        $repository = app()->container()->get('config');

        return $repository->get($key, $default);
    }
}

if (!function_exists('env')) {
    /**
     * Read an environment variable from the configured application.
     */
    function env(string $key, bool|int|float|string|null $default = null): mixed
    {
        /** @var Environment|null $environment */
        $environment = app()->container()->get('env');

        return $environment->get($key, $default);
    }
}

if (!function_exists('storage_path')) {
    /**
     * Get the path to the storage folder.
     */
    function storage_path(string $path = ''): string
    {
        return app()->basePath('storage'.($path !== '' ? '/'.$path : ''));
    }
}

if (!function_exists('base_path')) {
    /**
     * Get the path to the application base folder.
     */
    function base_path(string $path = ''): string
    {
        return app()->basePath($path);
    }
}

if (!function_exists('session')) {
    /**
     * Get / set the specified session value.
     *
     * If an array is passed as the key, we will assume you want to set an array of values.
     *
     * @param array<string, mixed>|string|null $key
     * @param mixed $default
     */
    function session(array|string|null $key = null, mixed $default = null): mixed
    {
        /** @var Sahil\Contracts\Session\SessionContract|null $session */
        $session = app()->container()->get(Sahil\Contracts\Session\SessionContract::class);

        if ($key === null) {
            return $session;
        }

        if (is_array($key)) {
            foreach ($key as $k => $v) {
                $session->put($k, $v);
            }

            return null;
        }

        return $session->get($key, $default);
    }
}

if (!function_exists('csrf_token')) {
    /**
     * Get the CSRF token value.
     */
    function csrf_token(): string
    {
        /** @var Sahil\Contracts\Session\SessionContract $session */
        $session = app()->container()->get(Sahil\Contracts\Session\SessionContract::class);

        return $session->token();
    }
}
