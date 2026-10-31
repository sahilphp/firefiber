<?php

declare(strict_types=1);

namespace Sahil\Firefiber\Tests;

use PHPUnit\Framework\TestCase;
use Sahil\Contracts\Assets\AssetManagerContract;
use Sahil\Firefiber\Assets\AssetRepository;
use Sahil\Firefiber\Assets\FirefiberAssetManager;
use Sahil\Firefiber\Assets\RuntimeAssets;
use Sahil\Firefiber\FirefiberServiceProvider;
use Sahil\Firefiber\Http\AssetController;
use Sahil\Foundation\Application;
use Sahil\Http\Kernel;
use Sahil\Http\Request;
use Sahil\Http\Router;

final class AssetDeliveryTest extends TestCase
{
    private AssetRepository $assets;

    protected function setUp(): void
    {
        $this->assets = new AssetRepository();
    }

    public function test_package_assets_are_allow_listed_and_resolve_from_the_composer_package(): void
    {
        $this->assertSame(
            ['runtime.min.js', 'runtime.js', 'firefiber.min.css', 'firefiber.css'],
            $this->assets->names(),
        );

        $production = $this->assets->find('runtime.min.js');
        $source = $this->assets->find('runtime.js');
        $productionCss = $this->assets->find('firefiber.min.css');
        $sourceCss = $this->assets->find('firefiber.css');

        $this->assertNotNull($production);
        $this->assertNotNull($source);
        $this->assertNotNull($productionCss);
        $this->assertNotNull($sourceCss);
        $this->assertTrue($production->exists());
        $this->assertTrue($source->exists());
        $this->assertTrue($productionCss->exists());
        $this->assertTrue($sourceCss->exists());

        $packageRoot = realpath(AssetRepository::defaultPackageRoot());
        $this->assertNotFalse($packageRoot);

        foreach ([$production, $source, $productionCss, $sourceCss] as $asset) {
            $path = realpath($asset->path());
            $this->assertNotFalse($path);
            $this->assertStringStartsWith($packageRoot.DIRECTORY_SEPARATOR, $path);
        }

        $this->assertSame('js/firefiber.js', substr($source->path(), -strlen('js/firefiber.js')));
        $this->assertSame('css/firefiber.css', substr($sourceCss->path(), -strlen('css/firefiber.css')));
    }

    public function test_invalid_public_prefixes_cannot_turn_asset_urls_into_external_urls(): void
    {
        foreach (['//evil.example/assets', 'https://evil.example/assets', '/assets/../evil', '/asset s'] as $prefix) {
            $repository = new AssetRepository('', $prefix);
            $this->assertSame('/firefiber/assets', $repository->publicPrefix(), $prefix);
            $this->assertStringStartsWith('/firefiber/assets/', $repository->runtimeUrl());
        }

        $custom = new AssetRepository('', '/custom/assets/');
        $this->assertSame('/custom/assets', $custom->publicPrefix());
        $this->assertSame('/custom/assets/runtime.min.js?v='.$custom->runtimeAsset()->version(), $custom->runtimeUrl());
    }

    public function test_assets_resolving_outside_the_package_root_are_rejected(): void
    {
        $temporaryRoot = sys_get_temp_dir().'/firefiber_symlink_'.bin2hex(random_bytes(8));
        mkdir($temporaryRoot.'/dist', 0755, true);
        symlink('/etc/passwd', $temporaryRoot.'/dist/runtime.min.js');

        try {
            $repository = new AssetRepository($temporaryRoot);
            $this->assertNull($repository->find('runtime.min.js'));
        } finally {
            unlink($temporaryRoot.'/dist/runtime.min.js');
            rmdir($temporaryRoot.'/dist');
            rmdir($temporaryRoot);
        }
    }

    public function test_assets_resolving_outside_the_approved_directories_are_rejected(): void
    {
        $temporaryRoot = sys_get_temp_dir().'/firefiber_approved_'.bin2hex(random_bytes(8));
        mkdir($temporaryRoot.'/dist', 0755, true);
        file_put_contents($temporaryRoot.'/composer.json', '{"name":"inside-the-package"}');
        symlink($temporaryRoot.'/composer.json', $temporaryRoot.'/dist/runtime.min.js');

        try {
            $repository = new AssetRepository($temporaryRoot);
            $this->assertNull($repository->find('runtime.min.js'));
        } finally {
            unlink($temporaryRoot.'/dist/runtime.min.js');
            unlink($temporaryRoot.'/composer.json');
            rmdir($temporaryRoot.'/dist');
            rmdir($temporaryRoot);
        }
    }

    public function test_unknown_asset_names_are_rejected(): void
    {
        $this->assertNull($this->assets->find('firefiber.min.css.map'));
        $this->assertNull($this->assets->find('runtime.css'));
        $this->assertNull($this->assets->find('manifest.json'));
        $this->assertNull($this->assets->find(''));

        $response = $this->controller()->show($this->request(), ['asset' => 'firefiber.min.css.map']);

        $this->assertSame(404, $response->status());
        $this->assertSame('no-store', $response->header('Cache-Control'));
        $this->assertStringNotContainsString('assets', $response->body());
    }

    public function test_path_traversal_and_encoded_path_attempts_are_rejected(): void
    {
        $attacks = [
            '../composer.json',
            '..%2Fcomposer.json',
            '..%2f..%2fetc%2fpasswd',
            '%2e%2e%2fcomposer.json',
            '..\\composer.json',
            'subdir/runtime.min.js',
            "runtime.min.js\0",
            '/etc/passwd',
        ];

        foreach ($attacks as $attack) {
            $this->assertNull($this->assets->find($attack), $attack);
            $response = $this->controller()->show($this->request(), ['asset' => $attack]);
            $this->assertSame(404, $response->status(), $attack);
            $this->assertStringNotContainsString('firefiber', strtolower($response->body()), $attack);
        }
    }

    public function test_assets_are_served_with_correct_mime_type_and_versioned_immutable_caching(): void
    {
        $asset = $this->assets->find('runtime.min.js');
        $this->assertNotNull($asset);

        $response = $this->controller()->show(
            $this->request(['v' => $asset->version()]),
            ['asset' => 'runtime.min.js'],
        );

        $this->assertSame(200, $response->status());
        $this->assertSame('application/javascript; charset=utf-8', $response->header('Content-Type'));
        $this->assertSame('public, max-age=31536000, immutable', $response->header('Cache-Control'));
        $this->assertSame($asset->etag(), $response->header('ETag'));
        $this->assertSame('nosniff', $response->header('X-Content-Type-Options'));
        $this->assertSame(file_get_contents($asset->path()), $response->body());

        $unversioned = $this->controller()->show($this->request(), ['asset' => 'runtime.min.js']);
        $this->assertSame(200, $unversioned->status());
        $this->assertSame('public, max-age=3600, must-revalidate', $unversioned->header('Cache-Control'));

        $wrongVersion = $this->controller()->show(
            $this->request(['v' => '000000000000']),
            ['asset' => 'runtime.min.js'],
        );
        $this->assertSame('public, max-age=3600, must-revalidate', $wrongVersion->header('Cache-Control'));
    }

    public function test_css_asset_is_served_with_correct_mime_type_and_versioned_immutable_caching(): void
    {
        $asset = $this->assets->find('firefiber.min.css');
        $this->assertNotNull($asset);

        $response = $this->controller()->show(
            $this->request(['v' => $asset->version()]),
            ['asset' => 'firefiber.min.css'],
        );

        $this->assertSame(200, $response->status());
        $this->assertSame('text/css; charset=utf-8', $response->header('Content-Type'));
        $this->assertSame('public, max-age=31536000, immutable', $response->header('Cache-Control'));
        $this->assertSame($asset->etag(), $response->header('ETag'));
        $this->assertSame('nosniff', $response->header('X-Content-Type-Options'));
        $this->assertSame(file_get_contents($asset->path()), $response->body());
    }

    public function test_conditional_requests_return_not_modified(): void
    {
        $asset = $this->assets->find('runtime.min.js');
        $this->assertNotNull($asset);

        $etagResponse = $this->controller()->show(
            $this->request([], ['HTTP_IF_NONE_MATCH' => 'W/'.$asset->etag()]),
            ['asset' => 'runtime.min.js'],
        );
        $this->assertSame(304, $etagResponse->status());
        $this->assertSame('', $etagResponse->body());

        $lastModified = gmdate('D, d M Y H:i:s', $asset->lastModified()).' GMT';
        $timeResponse = $this->controller()->show(
            $this->request([], ['HTTP_IF_MODIFIED_SINCE' => $lastModified]),
            ['asset' => 'runtime.min.js'],
        );
        $this->assertSame(304, $timeResponse->status());
    }

    public function test_unsupported_methods_are_rejected_without_serving_the_file(): void
    {
        $response = $this->controller()->show(
            $this->request([], ['REQUEST_METHOD' => 'POST']),
            ['asset' => 'runtime.min.js'],
        );

        $this->assertSame(405, $response->status());
        $this->assertSame('GET, HEAD', $response->header('Allow'));
        $this->assertSame('{"error":"method_not_allowed"}', $response->body());
    }

    public function test_known_but_missing_builds_return_a_generic_service_unavailable_response(): void
    {
        $temporaryRoot = sys_get_temp_dir().'/firefiber_assets_'.bin2hex(random_bytes(8));
        mkdir($temporaryRoot, 0755, true);

        try {
            $repository = new AssetRepository($temporaryRoot);
            $response = (new AssetController($repository))->show(
                $this->request(),
                ['asset' => 'runtime.min.js'],
            );

            $this->assertSame(503, $response->status());
            $this->assertSame('no-store', $response->header('Cache-Control'));
            $this->assertStringNotContainsString($temporaryRoot, $response->body());
            $this->assertStringNotContainsString('runtime.min.js', $response->body());
        } finally {
            rmdir($temporaryRoot);
        }
    }

    public function test_runtime_loading_uses_the_package_alias_integrity_and_version(): void
    {
        $runtime = new RuntimeAssets($this->assets, false);
        $url = $runtime->url();
        $tag = $runtime->scriptTag(['nonce' => 'nonce"<']);

        $this->assertStringStartsWith('/firefiber/assets/runtime.min.js?v=', $url);
        $this->assertStringContainsString('src="'.$url.'"', $tag);
        $this->assertStringContainsString('integrity="sha384-', $tag);
        $this->assertStringContainsString('crossorigin="anonymous"', $tag);
        $this->assertStringContainsString('nonce="nonce&quot;&lt;"', $tag);
        $this->assertStringContainsString(' defer', $tag);
        $this->assertStringNotContainsString('onload', $runtime->scriptTag(['onload' => 'alert(1)']));
        $this->assertStringNotContainsString('src="https://evil.example"', $runtime->scriptTag(['src' => 'https://evil.example']));

        $development = new RuntimeAssets($this->assets, true);
        $this->assertStringStartsWith('/firefiber/assets/runtime.js?v=', $development->url());
    }

    public function test_production_build_contains_the_required_optimized_assets(): void
    {
        $source = (string) file_get_contents(AssetRepository::defaultPackageRoot().'/js/firefiber.js');
        $cssSource = (string) file_get_contents(AssetRepository::defaultPackageRoot().'/css/firefiber.css');
        $builtPath = AssetRepository::defaultPackageRoot().'/dist/runtime.min.js';
        $cssBuiltPath = AssetRepository::defaultPackageRoot().'/dist/firefiber.min.css';
        $manifestPath = AssetRepository::defaultPackageRoot().'/dist/manifest.json';

        $this->assertFileExists($builtPath);
        $this->assertFileExists($cssBuiltPath);
        $this->assertFileExists($manifestPath);

        $built = (string) file_get_contents($builtPath);
        $builtCss = (string) file_get_contents($cssBuiltPath);
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertLessThan(strlen($source), strlen($built));
        $this->assertLessThan(strlen($cssSource), strlen($builtCss));
        $this->assertSame(hash('sha256', $source), $manifest['source_sha256']);
        $this->assertSame(hash('sha256', $built), $manifest['runtime_sha256']);
        $this->assertSame('sha384-'.base64_encode(hash('sha384', $built, true)), $manifest['runtime_sri']);
        $this->assertTrue($manifest['minified']);
        $this->assertArrayHasKey('obfuscated', $manifest);

        $this->assertSame(hash('sha256', $cssSource), $manifest['css']['source_sha256']);
        $this->assertSame(hash('sha256', $builtCss), $manifest['css']['css_sha256']);
        $this->assertSame('sha384-'.base64_encode(hash('sha384', $builtCss, true)), $manifest['css']['css_sri']);
        $this->assertTrue($manifest['css']['minified']);
        $this->assertSame('runtime.min.js', $manifest['aliases']['@fiberscripts']);
        $this->assertSame('firefiber.min.css', $manifest['aliases']['@fiberstyles']);
    }

    public function test_provider_registers_the_asset_route_without_copying_assets_into_the_application(): void
    {
        $applicationRoot = sys_get_temp_dir().'/firefiber_consumer_'.bin2hex(random_bytes(8));
        mkdir($applicationRoot.'/public', 0755, true);
        mkdir($applicationRoot.'/resources', 0755, true);

        Application::free();
        $application = Application::configure($applicationRoot);
        $router = new Router();
        $application->container()->instance(Router::class, $router);

        try {
            $provider = new FirefiberServiceProvider($application);
            $provider->register();
            $provider->boot();

            $manager = $application->container()->make(AssetManagerContract::class);
            $this->assertInstanceOf(FirefiberAssetManager::class, $manager);
            $this->assertStringContainsString(
                '/firefiber/assets/runtime.min.js?v=',
                $manager->js('@fiberscripts'),
            );
            $this->assertStringContainsString(
                '/firefiber/assets/firefiber.min.css?v=',
                $manager->css('@fiberstyles'),
            );

            $route = $router->routeByName(AssetRepository::ROUTE_NAME);
            $this->assertNotNull($route);
            $this->assertSame('/firefiber/assets/{asset}', $route->path());
            $this->assertContains('GET', $route->methods());
            $this->assertContains('HEAD', $route->methods());

            $match = $router->match($this->request());
            $this->assertSame('runtime.min.js', $match->params()['asset']);

            $kernel = new Kernel($router, $application->container(), $application);
            $response = $kernel->handle($this->request());

            $this->assertSame(200, $response->status());
            $this->assertSame('application/javascript; charset=utf-8', $response->header('Content-Type'));
            $this->assertSame($this->assets->find('runtime.min.js')?->contents(), $response->body());

            $this->assertFileDoesNotExist($applicationRoot.'/public/firefiber');
            $this->assertFileDoesNotExist($applicationRoot.'/resources/firefiber');
            $this->assertSame(['.','..'], scandir($applicationRoot.'/public'));
        } finally {
            Application::free();
            $this->removeDirectory($applicationRoot);
        }
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string> $server
     */
    private function request(array $query = [], array $server = []): Request
    {
        return Request::fromGlobals(
            array_replace([
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/firefiber/assets/runtime.min.js',
                'REQUEST_SCHEME' => 'https',
                'HTTP_HOST' => 'firefiber.test',
                'SERVER_PROTOCOL' => 'HTTP/1.1',
            ], $server),
            $query,
        );
    }

    private function controller(): AssetController
    {
        return new AssetController($this->assets);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($directory);
    }
}
