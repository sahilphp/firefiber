<?php

declare(strict_types=1);

namespace Sahil\Foundation;

use RuntimeException;
use Sahil\Contracts\Foundation\ApplicationContract;

/**
 * Reads a provider manifest file (a PHP file returning provider class names)
 * and instantiates the providers in order.
 *
 * Used by `bootstrap/app.php` for real requests and by the testing harness,
 * so an application test and a real request boot exactly the same component
 * graph.
 */
final class ProviderManifest
{
    /**
     * @return list<ServiceProvider>
     */
    public static function load(string $file, ApplicationContract $application): array
    {
        if (!is_file($file)) {
            throw new RuntimeException(sprintf('Provider manifest [%s] is missing.', $file));
        }

        /** @var mixed $classes */
        $classes = require $file;

        if (!is_array($classes)) {
            throw new RuntimeException(sprintf('Provider manifest [%s] must return a list of provider class names.', $file));
        }

        $providers = [];

        foreach ($classes as $class) {
            if (!is_string($class) || !class_exists($class)) {
                throw new RuntimeException(sprintf(
                    'Provider manifest [%s] entry must be an existing provider class; got %s.',
                    $file,
                    is_string($class) ? $class : get_debug_type($class),
                ));
            }

            $provider = new $class($application);

            if (!$provider instanceof ServiceProvider) {
                throw new RuntimeException(sprintf(
                    'Provider manifest [%s] entry [%s] must extend %s.',
                    $file,
                    $class,
                    ServiceProvider::class,
                ));
            }

            $providers[] = $provider;
        }

        return $providers;
    }

    /**
     * Load the conventional manifest for an application base path; a missing
     * file means "no providers", which is valid for minimal installations.
     *
     * @return list<ServiceProvider>
     */
    public static function loadFor(string $basePath, ApplicationContract $application): array
    {
        $file = rtrim($basePath, '/').'/bootstrap/providers.php';

        if (!is_file($file)) {
            return [];
        }

        return self::load($file, $application);
    }
}
