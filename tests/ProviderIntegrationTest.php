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
        // Discovery no longer publishes routes: every page must be mounted
        // explicitly through Link::firefiber() in the application's routes.
        self::assertNull($router->routeByName('firefiber.page.firefiber.demo'));

        foreach ($router->routes() as $route) {
            self::assertNotSame('/firefiber/demo', $route->path());
        }
        self::assertContains('/firefiber', $application->config()->get('session.csrf_except', []));
        self::assertTrue($application->container()->bound(\Sahil\Firefiber\Http\FirefiberMiddleware::class));
    }

    public function test_link_firefiber_mounts_page_classes_without_controllers(): void
    {
        $generator = new FirefiberGenerator($this->root);
        $generator->generatePage('Demo', false, null, [], true);

        $application = Application::configure($this->root);
        $application->config()->set('app.key', 'base64:'.base64_encode(str_repeat('f', 32)));
        $application->config()->set('app.environment', 'development');
        $application->config()->set('app.name', 'Provider Test');

        $provider = new FirefiberServiceProvider($application);
        $provider->register();
        $provider->boot();

        // Convention form: the page's own path() becomes the route path.
        $link = \Sahil\Routing\Link::firefiber(\Acme\Firefiber\Pages\DemoPage::class)->name('demo.home');

        self::assertSame('/firefiber/demo', $link->path());
        self::assertSame([\Acme\Firefiber\Pages\DemoPage::class, 'show'], $link->handler());

        // Explicit form: any path template, including parameters.
        $custom = \Sahil\Routing\Link::firefiber('/posts/{id}', \Acme\Firefiber\Pages\DemoPage::class);

        self::assertSame('/posts/{id}', $custom->path());
    }

    public function test_link_firefiber_rejects_non_page_classes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/expects a page class implementing/');

        \Sahil\Routing\Link::firefiber(\stdClass::class);
    }

    public function test_page_mount_receives_route_parameters(): void
    {
        $generator = new FirefiberGenerator($this->root);
        $generator->generatePage('Demo', false, null, [], true);

        $application = Application::configure($this->root);
        $application->config()->set('app.key', 'base64:'.base64_encode(str_repeat('f', 32)));
        $application->config()->set('app.environment', 'development');

        $provider = new FirefiberServiceProvider($application);
        $provider->register();
        $provider->boot();

        file_put_contents(
            $this->root.'/app/Firefiber/Pages/PostPage.php',
            <<<'PHP'
<?php

declare(strict_types=1);

namespace Acme\Firefiber\Pages;

use Sahil\Firefiber\Component;
use Sahil\Firefiber\Page;
use Sahil\Http\Request;

final class PostPage extends Page
{
    public array $received = [];

    public function path(): string
    {
        return '/posts';
    }

    public function title(): string
    {
        return 'Post';
    }

    protected function mount(array $params = [], ?Request $request = null): void
    {
        $this->received = $params;
    }

    public function components(): array
    {
        return [];
    }
}
PHP,
        );

        /** @var \Acme\Firefiber\Pages\PostPage $page */
        $page = $application->container()->make(\Acme\Firefiber\Pages\PostPage::class);
        $page->show(\Sahil\Http\Request::fromGlobals([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/posts/7',
            'REQUEST_SCHEME' => 'http',
            'HTTP_HOST' => 'app.test',
        ], [], [], [], ''), ['id' => '7']);

        self::assertSame(['id' => '7'], $page->received);
    }

    public function test_provider_no_longer_registers_a_render_environment(): void
    {
        $application = Application::configure($this->root);
        $application->config()->set('app.key', 'base64:'.base64_encode(str_repeat('f', 32)));

        $provider = new FirefiberServiceProvider($application);
        $provider->register();
        $provider->boot();

        // Pages mount on the route table, so the unified render manager is
        // left to the application's own providers.
        self::assertFalse($application->container()->bound(\Sahil\Rendering\RenderManager::class));
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
