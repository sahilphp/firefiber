<?php

declare(strict_types=1);

namespace Sahil\Firefiber\Tests;

use PHPUnit\Framework\TestCase;
use Sahil\Contracts\Session\SessionContract;
use Sahil\Firefiber\Component;
use Sahil\Firefiber\Exceptions\UnknownActionException;
use Sahil\Firefiber\FirefiberServiceProvider;
use Sahil\Firefiber\Http\FirefiberMiddleware;
use Sahil\Firefiber\Page;
use Sahil\Firefiber\PageRenderer;
use Sahil\Firefiber\Protocol;
use Sahil\Foundation\Application;
use Sahil\Http\Request;
use Sahil\Http\Response;
use Sahil\Http\Router;
use Sahil\Routing\Link;
use Sahil\Session\FileSessionDriver;
use Sahil\Session\Session;

/**
 * End-to-end coverage for the first-class page architecture: direct route
 * mounting, mount-vs-hydrate lifecycle, page-level actions through the
 * signed snapshot pipeline, and reserved-method protection.
 */
final class PageLifecycleTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        LifecycleCounterPage::$mountCalls = 0;
        LifecycleCounterPage::$bootedCalls = 0;

        $this->root = sys_get_temp_dir().'/firefiber-lifecycle-'.bin2hex(random_bytes(6));
        foreach (['/app', '/config', '/storage/sessions', '/resources/views'] as $dir) {
            self::assertTrue(mkdir($this->root.$dir, 0o700, true));
        }
    }

    protected function tearDown(): void
    {
        Application::free();
        $this->removeDirectory($this->root);
    }

    private function application(): Application
    {
        $application = Application::configure($this->root);
        $application->config()->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $application->config()->set('app.name', 'Lifecycle Test');

        $router = new Router();
        $application->container()->instance(Router::class, $router);

        $session = new Session(new FileSessionDriver($this->root.'/storage/sessions'));
        $session->startFromRequest($this->getRequest('/'));
        $application->container()->instance(SessionContract::class, $session);

        $provider = new FirefiberServiceProvider($application);
        $provider->register();
        $provider->boot();

        return $application;
    }

    private function getRequest(string $uri): Request
    {
        return Request::fromGlobals([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => $uri,
            'REQUEST_SCHEME' => 'http',
            'HTTP_HOST' => 'lifecycle.test',
        ], [], [], [], '');
    }

    /**
     * Drive a Firefiber action request against the middleware the provider
     * registered, using the page's own snapshot.
     *
     * @return array{status: int, payload: array<string, mixed>}
     */
    private function dispatch(Application $application, array $snapshot, string $action, array $payload = []): array
    {
        $session = $application->container()->make(SessionContract::class);
        $middleware = $application->container()->make(FirefiberMiddleware::class);

        $body = json_encode([
            'protocolVersion' => Protocol::PROTOCOL_VERSION,
            'type' => 'handleAction',
            'requestId' => 'req-'.bin2hex(random_bytes(8)),
            'componentId' => $snapshot['componentId'],
            'componentClass' => $snapshot['componentClass'],
            'action' => $action,
            'payload' => $payload,
            'csrfToken' => $session->token(),
            'signature' => $snapshot['signature'],
            'snapshot' => $snapshot,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $request = Request::fromGlobals([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/firefiber',
            'REQUEST_SCHEME' => 'http',
            'HTTP_HOST' => 'lifecycle.test',
            'HTTP_X_FIREFIBER_TARGET' => '1',
            'HTTP_X_CSRF_TOKEN' => $session->token(),
            'CONTENT_TYPE' => 'application/json',
        ], [], [], [], $body);

        $response = $middleware->handle($request, static function (): never {
            throw new \RuntimeException('The action endpoint must be intercepted.');
        });

        return [
            'status' => $response->status(),
            'payload' => json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR),
        ];
    }

    private function pageSnapshot(string $html, string $class): array
    {
        preg_match_all('/data-firefiber-snapshot="([^"]+)"/', $html, $matches);
        self::assertNotEmpty($matches[1], 'Rendered page must carry at least one signed snapshot.');

        foreach ($matches[1] as $encoded) {
            $decoded = json_decode(html_entity_decode($encoded, ENT_QUOTES, 'UTF-8'), true, 512, JSON_THROW_ON_ERROR);

            if (($decoded['componentClass'] ?? null) === $class) {
                return $decoded;
            }
        }

        self::fail('No snapshot found for page class '.$class);
    }

    public function test_link_firefiber_route_renders_a_reactive_page_root(): void
    {
        $application = $this->application();
        $router = $application->container()->make(Router::class);
        $router->register(Link::firefiber('/counter', LifecycleCounterPage::class)->name('counter'));

        $match = $router->match($this->getRequest('/counter'));
        self::assertNotNull($match->route());

        $response = $application->container()->make(LifecycleCounterPage::class)
            ->show($this->getRequest('/counter'), []);

        self::assertSame(200, $response->status());
        $html = $response->body();

        // The page itself is a reactive root carrying its own snapshot.
        self::assertStringContainsString('class="firefiber-page"', $html);
        self::assertStringContainsString('data-firefiber-component-name="'.LifecycleCounterPage::class.'"', $html);
        self::assertStringContainsString('data-firefiber-snapshot="', $html);
        self::assertStringContainsString('<title>Counter</title>', $html);
        self::assertSame(1, LifecycleCounterPage::$mountCalls);
    }

    public function test_page_action_updates_state_and_returns_a_fresh_signed_snapshot(): void
    {
        $application = $this->application();

        $response = $application->container()->make(LifecycleCounterPage::class)
            ->show($this->getRequest('/counter'), []);
        $snapshot = $this->pageSnapshot($response->body(), LifecycleCounterPage::class);

        self::assertSame(0, $snapshot['state']['visits']);
        self::assertSame(1, LifecycleCounterPage::$mountCalls);

        $first = $this->dispatch($application, $snapshot, 'increment');
        self::assertSame(200, $first['status'], (string) ($first['payload']['error'] ?? ''));
        self::assertSame(1, $first['payload']['snapshot']['state']['visits']);

        // mount() never re-runs on action requests — the page hydrates.
        self::assertSame(1, LifecycleCounterPage::$mountCalls);
        self::assertSame(1, LifecycleCounterPage::$bootedCalls);

        $second = $this->dispatch($application, $first['payload']['snapshot'], 'increment');
        self::assertSame(200, $second['status']);
        self::assertSame(2, $second['payload']['snapshot']['state']['visits']);
        self::assertSame(1, LifecycleCounterPage::$mountCalls);
        self::assertSame(2, LifecycleCounterPage::$bootedCalls);
    }

    public function test_route_parameters_reach_mount_and_survive_hydration(): void
    {
        $application = $this->application();
        $router = $application->container()->make(Router::class);
        $router->register(Link::firefiber('/posts/{id}', LifecycleCounterPage::class));

        $match = $router->match($this->getRequest('/posts/42'));
        self::assertSame(['id' => '42'], $match->params());

        $response = $application->container()->make(LifecycleCounterPage::class)
            ->show($this->getRequest('/posts/42'), $match->params());
        $snapshot = $this->pageSnapshot($response->body(), LifecycleCounterPage::class);

        self::assertSame('42', $snapshot['state']['mountedId']);

        // A hydrated page still knows which route produced it.
        $result = $this->dispatch($application, $snapshot, 'readRouteParam');
        self::assertSame(200, $result['status'], (string) ($result['payload']['error'] ?? ''));
        self::assertSame('42', $result['payload']['result']['value']);
    }

    public function test_lifecycle_and_internal_methods_are_never_browser_callable(): void
    {
        $application = $this->application();

        $response = $application->container()->make(LifecycleCounterPage::class)
            ->show($this->getRequest('/counter'), []);
        $snapshot = $this->pageSnapshot($response->body(), LifecycleCounterPage::class);

        foreach (['mount', 'show', 'render', 'view', 'hydrate', 'authorize', 'snapshot', 'updateState'] as $action) {
            $result = $this->dispatch($application, $snapshot, $action);
            self::assertSame(404, $result['status'], $action.' must not be dispatchable');
        }
    }

    public function test_unlisted_public_methods_are_rejected(): void
    {
        $application = $this->application();

        $response = $application->container()->make(LifecycleCounterPage::class)
            ->show($this->getRequest('/counter'), []);
        $snapshot = $this->pageSnapshot($response->body(), LifecycleCounterPage::class);

        $result = $this->dispatch($application, $snapshot, 'secretReset');
        self::assertSame(404, $result['status']);
    }

    public function test_model_binding_writes_only_modelable_page_state(): void
    {
        $application = $this->application();

        $response = $application->container()->make(LifecycleCounterPage::class)
            ->show($this->getRequest('/counter'), []);
        $snapshot = $this->pageSnapshot($response->body(), LifecycleCounterPage::class);

        $result = $this->dispatch($application, $snapshot, Component::MODEL_ACTION, ['note' => 'hello']);
        self::assertSame(200, $result['status'], (string) ($result['payload']['error'] ?? ''));
        self::assertSame('hello', $result['payload']['snapshot']['state']['note']);

        // `__route` (route params) and any undeclared key are not modelable.
        $rejected = $this->dispatch($application, $snapshot, Component::MODEL_ACTION, ['__route' => ['id' => '0']]);
        self::assertContains($rejected['status'], [404, 409, 422]);
    }

    public function test_csrf_tokens_survive_session_token_rotation(): void
    {
        $application = $this->application();
        $session = $application->container()->make(SessionContract::class);

        $response = $application->container()->make(LifecycleCounterPage::class)
            ->show($this->getRequest('/counter'), []);
        $snapshot = $this->pageSnapshot($response->body(), LifecycleCounterPage::class);

        // A later request mints a fresh token: the page-rendered one is
        // retired into the session's history ring. Action requests carrying
        // it must still validate — issueToken() semantics, not token().
        $session->issueToken();

        $result = $this->dispatch($application, $snapshot, 'increment');
        self::assertSame(200, $result['status'], (string) ($result['payload']['error'] ?? ''));
        self::assertSame(1, $result['payload']['snapshot']['state']['visits']);
    }

    public function test_component_handle_action_dispatches_to_public_methods_by_default(): void
    {
        $counter = new LifecycleInlineCounter();
        $counter->handleAction('bump', ['by' => 3]);
        self::assertSame(3, $counter->state()['count']);

        $this->expectException(UnknownActionException::class);
        $counter->handleAction('mount', []);
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
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}

final class LifecycleCounterPage extends Page
{
    public static int $mountCalls = 0;
    public static int $bootedCalls = 0;

    protected array $state = ['visits' => 0, 'note' => '', 'mountedId' => ''];
    protected array $actions = ['increment', 'readRouteParam'];
    protected array $modelable = ['note'];

    public function path(): string
    {
        return '/counter';
    }

    public function title(): string
    {
        return 'Counter';
    }

    protected function mount(array $params = [], ?Request $request = null): void
    {
        self::$mountCalls++;
        $this->state['mountedId'] = (string) ($params['id'] ?? '');
    }

    protected function booted(): void
    {
        self::$bootedCalls++;
    }

    public function increment(): void
    {
        $this->updateState(['visits' => (int) ($this->state['visits'] ?? 0) + 1]);
    }

    public function readRouteParam(): string
    {
        return (string) ($this->routeParams()['id'] ?? '');
    }

    /** Public but NOT allowlisted — must never be callable from the browser. */
    public function secretReset(): void
    {
        $this->updateState(['visits' => 0]);
    }

    public function components(): array
    {
        return [];
    }
}

final class LifecycleInlineCounter extends Component
{
    protected array $actions = ['bump'];

    public function __construct()
    {
        parent::__construct(['count' => 0]);
        $this->actions = ['bump'];
    }

    public function bump(int $by = 1): void
    {
        $this->updateState(['count' => (int) ($this->state['count'] ?? 0) + $by]);
    }

    public function render(): string
    {
        return '<div></div>';
    }
}
