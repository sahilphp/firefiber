<?php
declare(strict_types=1);

namespace App\Providers;

/**
 * Backwards-compatible application provider name.
 *
 * The canonical provider now lives in the package namespace
 * ({@see \Sahil\Firefiber\FirefiberServiceProvider}) so Composer can
 * autoload it. This alias keeps existing installations that reference
 * `App\Providers\FirefiberServiceProvider` working while giving them the
 * package-managed asset route as well.
 */
final class FirefiberServiceProvider extends \Sahil\Firefiber\FirefiberServiceProvider
{
}
