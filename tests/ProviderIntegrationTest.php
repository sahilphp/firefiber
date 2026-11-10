<?php

declare(strict_types=1);

namespace Sahil\Firefiber\Tests;

use PHPUnit\Framework\TestCase;
use Sahil\Contracts\Session\SessionContract;
use Sahil\Firefiber\FirefiberServiceProvider;
use Sahil\Firefiber\Generator\FirefiberGenerator;
use Sahil\Foundation\Application;
use Sahil\Http\Router;

final class ProviderIntegrationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/firefiber-provider-'.bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->root.'/app', 0o700, true));
        self::assertTrue(mkdir($this->root.'/config', 0o700, true));
        self::assertTrue(mkdir($this->root.'/storage', 0o700, true));
        self::assertTrue(mkdir($this->root.'/resources/views', 0o700, true));

        file_put_contents($this->root.'/composer.json', json_encode([
            'name' => 'acme/application',
            'autoload' => ['psr-4' => ['Acme\\' => 'app/']],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        spl_autoload_register(function (string $class): void {
            if (!str_starts_with($class, 'Acme\\')) {
                return;
            }

            $file = $this->root.'/app/'.str_replace('\\', '/', substr($class, 5)).'.php';

            if (is_file($file)) {
                require_once $file;
            }
        });
    }

    protected function tearDown(): void
    {
        Application::free();
        $this->removeDirectory($this->root);
    }

    public function test_provider_registers_assets_action_endpoint_and_discovered_page_routes(): void
    {
        $generator = new FirefiberGenerator($this->root);
        $generator->generatePage('Demo', false, null, [], true);

        $application = Application::configure($this->root);
        $application->config()->set('app.key', 'base64:'.base64_encode(str_repeat('f', 32)));
        $application->config()->set('app.environment', 'development');
        $application->config()->set('app.debug', false);
        $application->config()->set('app.name', 'Provider Test');

        $router = new Router();
        $application->container()->instance(Router::class, $router);
        $session = new \Sahil\Session\Session(new \Sahil\Session\FileSessionDriver($this->root.'/storage/sessions'));
        $application->container()->instance(SessionContract::class, $session);

        $provider = new FirefiberServiceProvider($application);
        $provider->register();
        $provider->boot();

        self::assertNotNull($router->routeByName('firefiber.assets'));
        self::assertNotNull($router->routeByName('firefiber.action'));
        self::assertNotNull($router->routeByName('firefiber.page.firefiber.demo'));
        self::assertContains('/firefiber', $application->config()->get('session.csrf_except', []));
        self::assertTrue($application->container()->bound(\Sahil\Firefiber\Http\FirefiberMiddleware::class));
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
                continue;
            }

            unlink($item->getPathname());
        }

        rmdir($path);
    }
}
