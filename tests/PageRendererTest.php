<?php

declare(strict_types=1);

namespace Sahil\Firefiber\Tests;

use PHPUnit\Framework\TestCase;
use Sahil\Firefiber\Component;
use Sahil\Firefiber\FirefiberSecrets;
use Sahil\Firefiber\Http\FirefiberMiddleware;
use Sahil\Firefiber\Page;
use Sahil\Firefiber\PageRenderer;
use Sahil\Firefiber\SnapshotIntegrity;
use Sahil\Firefiber\Storage\FileCacheReplayStorage;
use Sahil\Firefiber\Storage\FileCacheStateStorage;
use Sahil\Http\Request;

final class PageRendererTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/firefiber-page-'.bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->root.'/storage/framework/sessions', 0o700, true));
        self::assertTrue(mkdir($this->root.'/storage/framework/firefiber', 0o700, true));
        self::assertTrue(mkdir($this->root.'/state', 0o700, true));
        self::assertTrue(mkdir($this->root.'/replay', 0o700, true));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function test_a_rendered_component_can_be_driven_through_the_action_middleware(): void
    {
        $application = new PageRendererTestApplication($this->root);
        $session = new \Sahil\Session\Session(new \Sahil\Session\FileSessionDriver($this->root.'/storage/framework/sessions'));
        $session->startFromRequest(Request::fromGlobals([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/firefiber/demo',
            'REQUEST_SCHEME' => 'http',
            'HTTP_HOST' => 'firefiber.test',
        ], [], [], [], ''));

        $storage = new FileCacheStateStorage($this->root.'/state');
        $renderer = new PageRenderer(
            $application,
            $storage,
            new SnapshotIntegrity(FirefiberSecrets::masterKey($application)),
            null,
            $session,
        );
        $page = new class ($renderer) extends Page {
            public function path(): string
            {
                return '/firefiber/demo';
            }

            public function title(): string
            {
                return 'Demo';
            }

            /** @return list<Component> */
            public function components(): array
            {
                return [new PageRendererTestCounter()];
            }
        };

        $request = Request::fromGlobals([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/firefiber/demo',
            'REQUEST_SCHEME' => 'http',
            'HTTP_HOST' => 'firefiber.test',
        ], [], [], [], '');
        $response = $renderer->render($request, $page);
        self::assertSame(200, $response->status());

        $html = $response->body();
        self::assertStringContainsString('data-firefiber-component="', $html);
        self::assertStringContainsString('data-firefiber-component-name="Sahil\\Firefiber\\Tests\\PageRendererTestCounter"', $html);
        self::assertStringContainsString('data-firefiber-csrf="'.$session->token().'"', $html);
        self::assertStringNotContainsString('data-firefiber-key=', $html);
        self::assertStringNotContainsString('data-firefiber-hmac-key=', $html);
        self::assertStringNotContainsString(FirefiberSecrets::masterKey($application), $html);

        preg_match('/data-firefiber-snapshot="([^"]+)"/', $html, $snapshotMatches);
        self::assertNotEmpty($snapshotMatches);
        $snapshot = json_decode(html_entity_decode($snapshotMatches[1], ENT_QUOTES, 'UTF-8'), true, 512, JSON_THROW_ON_ERROR);

        $componentId = (string) $snapshot['componentId'];
        $middleware = new FirefiberMiddleware(
            new FileCacheReplayStorage($this->root.'/replay'),
            null,
            300,
            'page-renderer-test',
            $storage,
            null,
            0,
            $application,
            $session,
        );

        for ($expected = 1; $expected <= 2; $expected++) {
            $body = json_encode([
                'protocolVersion' => '3.1',
                'type' => 'handleAction',
                'requestId' => 'req-page-'.$expected.'-'.bin2hex(random_bytes(4)),
                'componentId' => $componentId,
                'componentName' => PageRendererTestCounter::class,
                'action' => 'increment',
                'payload' => [],
                'csrfToken' => $session->token(),
                'signature' => $snapshot['signature'],
                'snapshot' => $snapshot,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $actionRequest = Request::fromGlobals([
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/firefiber',
                'REQUEST_SCHEME' => 'http',
                'HTTP_HOST' => 'firefiber.test',
                'HTTP_X_FIREFIBER_TARGET' => '1',
                'HTTP_X_CSRF_TOKEN' => $session->token(),
                'CONTENT_TYPE' => 'application/json',
            ], [], [], [], $body);

            $actionResponse = $middleware->handle($actionRequest, static function (): never {
                throw new \RuntimeException('The action endpoint must be intercepted.');
            });

            self::assertSame(200, $actionResponse->status());
            $payload = json_decode($actionResponse->body(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame($expected, $payload['snapshot']['state']['count']);
            self::assertSame($expected, $storage->retrieveState($componentId)['count']);
            $snapshot = $payload['snapshot'];
        }
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

final class PageRendererTestCounter extends Component
{
    public function __construct()
    {
        parent::__construct(['count' => 0], ['increment']);
    }

    public function render(): string
    {
        $count = (int) ($this->state['count'] ?? 0);

        return '<div><span data-firefiber-bind="count">'.$count.'</span>'
            .'<button type="button" fiber:click="increment">Increment</button></div>';
    }

    public function handleAction(string $action, array $payload): array
    {
        if ($action !== 'increment') {
            throw new \Sahil\Firefiber\Exceptions\UnknownActionException($action, $this->id());
        }

        $count = (int) ($this->state['count'] ?? 0) + 1;
        $this->updateState(['count' => $count]);

        return ['count' => $count];
    }
}

final class PageRendererTestApplication implements \Sahil\Contracts\Foundation\ApplicationContract
{
    public function __construct(private readonly string $basePath)
    {
    }

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

    public function container(): \Sahil\Container\Container
    {
        return new \Sahil\Container\Container();
    }

    public function environment(): \Sahil\Support\Environment
    {
        return new \Sahil\Support\Environment([]);
    }

    public function config(): \Sahil\Config\Repository
    {
        return new \Sahil\Config\Repository(['app' => ['key' => 'base64:'.base64_encode(str_repeat('f', 32))]]);
    }

    public function isBooted(): bool
    {
        return true;
    }

    public function loadEnvironment(string $filename = '.env'): static
    {
        return $this;
    }

    public function loadConfiguration(string $directory = ''): static
    {
        return $this;
    }

    public function register(\Sahil\Foundation\ServiceProvider $provider): static
    {
        return $this;
    }

    public function boot(): void
    {
    }

    public function isolatedRequest(callable $work): mixed
    {
        return $work(null);
    }

    public function reset(): void
    {
    }
}
