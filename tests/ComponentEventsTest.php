<?php

declare(strict_types=1);

namespace Sahil\Firefiber\Tests;

use PHPUnit\Framework\TestCase;
use Sahil\Contracts\Session\SessionContract;
use Sahil\Firefiber\Attributes\On;
use Sahil\Firefiber\Component;
use Sahil\Firefiber\Exceptions\InvalidStateException;
use Sahil\Firefiber\Exceptions\UnknownActionException;
use Sahil\Firefiber\FirefiberServiceProvider;
use Sahil\Firefiber\Http\FirefiberMiddleware;
use Sahil\Firefiber\Page;
use Sahil\Firefiber\PageRenderer;
use Sahil\Firefiber\Protocol;
use Sahil\Foundation\Application;
use Sahil\Http\Request;
use Sahil\Session\FileSessionDriver;
use Sahil\Session\Session;

/**
 * Coverage for the event system: `#[On]` and `$listeners` resolution,
 * targeted dispatch (self/children/ancestors/broadcast/class/browser),
 * payload delivery, and propagation through the rendered component tree.
 */
final class ComponentEventsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/firefiber-events-'.bin2hex(random_bytes(6));
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

        $session = new Session(new FileSessionDriver($this->root.'/storage/sessions'));
        $session->startFromRequest(Request::fromGlobals([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'REQUEST_SCHEME' => 'http',
            'HTTP_HOST' => 'events.test',
        ], [], [], [], ''));
        $application->container()->instance(SessionContract::class, $session);

        $provider = new FirefiberServiceProvider($application);
        $provider->register();
        $provider->boot();

        return $application;
    }

    /**
     * Render a page composed of the given components; returns the decoded
     * snapshots keyed by component class.
     *
     * @param list<Component> $components
     * @return array<string, array<string, mixed>>
     */
    private function renderPage(Application $application, array $components): array
    {
        $renderer = $application->container()->make(PageRenderer::class);
        $page = new EventsHostPage($renderer);
        $page->items = $components;

        $response = $renderer->render($this->getRequest('/events'), $page);

        preg_match_all('/data-firefiber-snapshot="([^"]+)"/', $response->body(), $matches);
        self::assertNotEmpty($matches[1]);

        $snapshots = [];
        foreach ($matches[1] as $encoded) {
            $decoded = json_decode(html_entity_decode($encoded, ENT_QUOTES, 'UTF-8'), true, 512, JSON_THROW_ON_ERROR);
            $snapshots[(string) $decoded['componentClass']] = $decoded;
        }

        return $snapshots;
    }

    private function getRequest(string $uri): Request
    {
        return Request::fromGlobals([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => $uri,
            'REQUEST_SCHEME' => 'http',
            'HTTP_HOST' => 'events.test',
        ], [], [], [], '');
    }

    /**
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
            'HTTP_HOST' => 'events.test',
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

    public function test_emit_queues_a_broadcast_event_with_payload(): void
    {
        $component = new EventChildComponent();
        $component->emit('post-created', ['postId' => 7]);

        $events = $component->queuedEvents();
        self::assertCount(1, $events);
        self::assertSame('post-created', $events[0]['name']);
        self::assertSame(['postId' => 7], $events[0]['payload']);
        self::assertSame('broadcast', $events[0]['to']);
        self::assertNull($events[0]['target']);
    }

    public function test_targeted_dispatch_marks_queue_entries(): void
    {
        $component = new EventChildComponent();
        $component->dispatchSelf('refresh');
        $component->dispatchDown('reset');
        $component->dispatchTo(EventListenerComponent::class, 'ping', ['seq' => 2]);
        $component->dispatchBrowserEvent('notify', ['level' => 'info']);

        $events = $component->queuedEvents();
        self::assertSame('self', $events[0]['to']);
        self::assertSame('children', $events[1]['to']);
        self::assertSame('class', $events[2]['to']);
        self::assertSame(EventListenerComponent::class, $events[2]['target']);
        self::assertSame('browser', $events[3]['to']);
        self::assertSame('notify', $events[3]['name']);
    }

    public function test_on_attribute_listeners_receive_named_payload_arguments(): void
    {
        $listener = new EventListenerComponent();
        self::assertTrue($listener->hasListener('post-created'));

        $listener->handleEvent('post-created', ['postId' => 41, 'title' => 'Hello']);
        self::assertSame('41:Hello', $listener->state()['heard']);
    }

    public function test_listeners_map_and_attributes_both_run_for_one_event(): void
    {
        $listener = new EventListenerComponent();
        $listener->handleEvent('multi', ['value' => 'x']);

        // `$listeners['multi']` + `#[On('multi')]` each fired once.
        self::assertSame(2, $listener->state()['multiCount']);
    }

    public function test_broadcast_event_reaches_parent_and_sibling_listeners(): void
    {
        $application = $this->application();
        $snapshots = $this->renderPage($application, [
            new EventChildComponent(),
            new EventListenerComponent(),
        ]);

        $result = $this->dispatch($application, $snapshots[EventChildComponent::class], 'announce');
        self::assertSame(200, $result['status'], (string) ($result['payload']['error'] ?? ''));

        // The page (ancestor) and the sibling listener both handled it.
        $seen = [];
        foreach ($result['payload']['parentSnapshots'] as $snapshot) {
            $seen[] = $snapshot['componentClass'];
            if ($snapshot['componentClass'] === EventListenerComponent::class) {
                self::assertSame('99:from-child', $snapshot['state']['heard']);
            }
        }

        self::assertContains(EventListenerComponent::class, $seen);
        self::assertContains(EventsHostPage::class, $seen);
    }

    public function test_dispatch_to_reaches_only_the_targeted_class(): void
    {
        $application = $this->application();
        $snapshots = $this->renderPage($application, [
            new EventChildComponent(),
            new EventListenerComponent(),
        ]);

        $result = $this->dispatch($application, $snapshots[EventChildComponent::class], 'pingListener');

        self::assertSame(200, $result['status'], (string) ($result['payload']['error'] ?? ''));

        $classes = array_column($result['payload']['parentSnapshots'], 'componentClass');
        self::assertContains(EventListenerComponent::class, $classes);
        // The page doesn't listen for the targeted event — no delivery.
        self::assertNotContains(EventsHostPage::class, $classes);
    }

    public function test_dispatch_self_runs_the_own_listener_inline(): void
    {
        $application = $this->application();
        $snapshots = $this->renderPage($application, [new EventListenerComponent()]);

        $result = $this->dispatch($application, $snapshots[EventListenerComponent::class], 'selfRefresh');

        self::assertSame(200, $result['status'], (string) ($result['payload']['error'] ?? ''));
        self::assertSame('self-refreshed', $result['payload']['snapshot']['state']['heard']);
    }

    public function test_browser_events_are_returned_for_the_runtime(): void
    {
        $application = $this->application();
        $snapshots = $this->renderPage($application, [new EventChildComponent()]);

        $result = $this->dispatch($application, $snapshots[EventChildComponent::class], 'notifyBrowser');

        self::assertSame(200, $result['status']);
        $events = $result['payload']['events'];
        self::assertNotEmpty($events);

        $browser = array_values(array_filter($events, static fn (array $e): bool => ($e['to'] ?? '') === 'browser'));
        self::assertNotEmpty($browser);
        self::assertSame('notify', $browser[0]['name']);
        self::assertSame(['level' => 'warn'], $browser[0]['payload']);
        self::assertSame([], $browser[0]['delivered']);
    }

    public function test_delivered_component_ids_are_reported_in_the_event_channel(): void
    {
        $application = $this->application();
        $snapshots = $this->renderPage($application, [
            new EventChildComponent(),
            new EventListenerComponent(),
        ]);

        $result = $this->dispatch($application, $snapshots[EventChildComponent::class], 'announce');
        self::assertSame(200, $result['status']);

        $announce = array_values(array_filter(
            $result['payload']['events'],
            static fn (array $e): bool => ($e['name'] ?? '') === 'child-announced',
        ));
        self::assertNotEmpty($announce);
        self::assertContains($snapshots[EventListenerComponent::class]['componentId'], $announce[0]['delivered']);
    }

    public function test_event_state_changes_persist_through_hydration(): void
    {
        $application = $this->application();
        $snapshots = $this->renderPage($application, [
            new EventChildComponent(),
            new EventListenerComponent(),
        ]);

        $first = $this->dispatch($application, $snapshots[EventChildComponent::class], 'announce');
        self::assertSame(200, $first['status']);

        $listenerSnapshot = null;
        foreach ($first['payload']['parentSnapshots'] as $snapshot) {
            if ($snapshot['componentClass'] === EventListenerComponent::class) {
                $listenerSnapshot = $snapshot;
            }
        }

        self::assertNotNull($listenerSnapshot);
        self::assertSame('99:from-child', $listenerSnapshot['state']['heard']);

        // The listener's hydrated component keeps the event-driven state.
        $second = $this->dispatch($application, $listenerSnapshot, 'readHeard');
        self::assertSame(200, $second['status']);
        self::assertSame('99:from-child', $second['payload']['result']['value']);
    }

    public function test_invalid_and_undeclared_events_are_rejected(): void
    {
        $component = new EventChildComponent();

        $this->expectException(InvalidStateException::class);
        $component->emit('bad event name!');
    }

    public function test_emit_restricts_to_the_emits_allowlist(): void
    {
        $component = new EventStrictChildComponent();

        $this->expectException(UnknownActionException::class);
        $component->emit('not-allowed');
    }

    public function test_unknown_listener_events_fail_safely(): void
    {
        $listener = new EventListenerComponent();

        $this->expectException(UnknownActionException::class);
        $listener->handleEvent('never-declared', []);
    }

    public function test_base_component_methods_cannot_be_reached_as_listeners(): void
    {
        $component = new EventSneakyComponent();
        self::assertFalse($component->hasListener('sneak'));

        $this->expectException(UnknownActionException::class);
        $component->handleEvent('sneak', []);
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

final class EventsHostPage extends Page
{
    /** @var list<Component> */
    public array $items = [];

    public static int $announced = 0;

    public function path(): string
    {
        return '/events';
    }

    public function title(): string
    {
        return 'Events';
    }

    public function components(): array
    {
        return $this->items;
    }

    #[On('child-announced')]
    public function onChildAnnouncement(int $postId): void
    {
        self::$announced++;
    }
}

final class EventChildComponent extends Component
{
    protected array $state = ['sent' => 0];
    protected array $actions = ['announce', 'pingListener', 'notifyBrowser', 'selfRefresh'];

    public function __construct()
    {
        parent::__construct(['sent' => 0]);
    }

    public function announce(): void
    {
        $this->dispatch('child-announced', ['postId' => 99, 'title' => 'from-child']);
    }

    public function pingListener(): void
    {
        $this->dispatchTo(EventListenerComponent::class, 'post-created', ['postId' => 5, 'title' => 'direct']);
    }

    public function notifyBrowser(): void
    {
        $this->dispatchBrowserEvent('notify', ['level' => 'warn']);
    }

    public function render(): string
    {
        return '<div>child</div>';
    }
}

final class EventStrictChildComponent extends Component
{
    protected array $emits = ['allowed-only'];

    public function __construct()
    {
        parent::__construct([]);
    }

    public function render(): string
    {
        return '<div></div>';
    }
}

final class EventListenerComponent extends Component
{
    protected array $state = ['heard' => '', 'multiCount' => 0];
    protected array $actions = ['selfRefresh', 'readHeard'];
    protected array $listeners = ['multi' => 'onMultiMapped'];

    public function __construct()
    {
        parent::__construct(['heard' => '', 'multiCount' => 0]);
    }

    #[On('post-created')]
    public function refreshPosts(int $postId, string $title = ''): void
    {
        $this->updateState(['heard' => $postId.':'.$title]);
    }

    #[On('child-announced')]
    public function onAnnounced(int $postId, string $title = ''): void
    {
        $this->updateState(['heard' => $postId.':'.$title]);
    }

    #[On('multi')]
    public function onMultiAttribute(array $payload = []): void
    {
        $this->updateState(['multiCount' => (int) ($this->state['multiCount'] ?? 0) + 1]);
    }

    public function onMultiMapped(array $payload = []): void
    {
        $this->updateState(['multiCount' => (int) ($this->state['multiCount'] ?? 0) + 1]);
    }

    public function selfRefresh(): void
    {
        $this->dispatchSelf('refresh-now');
    }

    #[On('refresh-now')]
    public function onSelfRefresh(): void
    {
        $this->updateState(['heard' => 'self-refreshed']);
    }

    public function readHeard(): string
    {
        return (string) ($this->state['heard'] ?? '');
    }

    public function render(): string
    {
        return '<div>listener</div>';
    }
}

/** Maps an event to a method it must never reach (base-class internal). */
final class EventSneakyComponent extends Component
{
    protected array $listeners = ['sneak' => 'updateState'];

    public function __construct()
    {
        parent::__construct([]);
    }

    public function render(): string
    {
        return '<div></div>';
    }
}
