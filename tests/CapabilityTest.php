<?php

declare(strict_types=1);

namespace Sahil\Firefiber\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sahil\Contracts\Foundation\ApplicationContract;
use Sahil\Firefiber\Component;
use Sahil\Firefiber\ComponentParentRegistry;
use Sahil\Firefiber\Exceptions\InvalidStateException;
use Sahil\Firefiber\Exceptions\UnknownActionException;
use Sahil\Firefiber\Exceptions\ValidationException;
use Sahil\Firefiber\FirefiberSecrets;
use Sahil\Firefiber\Http\FirefiberMiddleware;
use Sahil\Firefiber\PageRenderer;
use Sahil\Firefiber\SnapshotIntegrity;
use Sahil\Firefiber\Storage\FileCacheReplayStorage;
use Sahil\Firefiber\Storage\FileCacheStateStorage;
use Sahil\Http\Request;

final class CapabilityTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/firefiber-capability-'.bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->root.'/storage/framework/sessions', 0o700, true));
        self::assertTrue(mkdir($this->root.'/state', 0o700, true));
        self::assertTrue(mkdir($this->root.'/replay', 0o700, true));
        self::assertTrue(mkdir($this->root.'/locks', 0o700, true));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function test_properties_are_validated_with_defaults_and_required_values(): void
    {
        $child = new CapabilityCounterChild(['step' => 3]);

        self::assertSame(3, $child->property('step'));
        self::assertSame('Counter', $child->property('label'));
        self::assertSame(3, $child->properties()['step']);

        try {
            new CapabilityCounterChild(['step' => 0]);
            self::fail('Expected an invalid-property exception.');
        } catch (InvalidStateException $exception) {
            self::assertStringContainsString('below its minimum value', $exception->getMessage());
        }

        try {
            new CapabilityCounterChild(['label' => 'Oops']);
            self::fail('Expected a missing-property exception.');
        } catch (InvalidStateException $exception) {
            self::assertStringContainsString('Missing required property [step]', $exception->getMessage());
        }

        try {
            new CapabilityCounterChild(['step' => 1, 'unknown' => true]);
            self::fail('Expected an unknown-property exception.');
        } catch (InvalidStateException $exception) {
            self::assertStringContainsString('Unknown property [unknown]', $exception->getMessage());
        }
    }

    public function test_event_payloads_are_validated_and_unknown_events_are_rejected(): void
    {
        $child = new CapabilityCounterChild(['step' => 2]);

        try {
            $child->emit('count-changed', ['count' => 'not-a-number', 'step' => 1]);
            self::fail('Expected an event-validation exception.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('count', $exception->errors());
        }

        try {
            $child->emit('unknown-event', []);
            self::fail('Expected an unknown-event exception.');
        } catch (UnknownActionException $exception) {
            self::assertStringContainsString('Unknown component event', $exception->getMessage());
        }
    }

    public function test_lifecycle_hooks_have_a_deterministic_order(): void
    {
        $component = new CapabilityLifecycleComponent();

        self::assertSame(['mount'], $component->trace);

        $component->hydrate($component->id(), ['count' => 0]);
        $component->callUpdating('increment', []);
        $component->callUpdated('increment', [], ['count' => 1]);
        $component->rendering();
        $component->destroy();

        self::assertSame(['mount', 'booted', 'updating', 'updated', 'rendering', 'destroyed'], $component->trace);
    }

    public function test_parent_receives_validated_child_events_and_state_stays_isolated(): void
    {
        [$renderer, $storage, $middleware, $session] = $this->services();

        $child = new CapabilityCounterChild(['step' => 2, 'label' => 'Parent counter']);
        $parent = new CapabilityParent($child);
        $html = $renderer->renderComponent($parent);

        self::assertStringContainsString($child->id(), $html);
        self::assertStringContainsString($parent->id(), $html);

        $otherChild = new CapabilityCounterChild(['step' => 1, 'label' => 'Other counter']);
        $otherHtml = $renderer->renderComponent($otherChild);
        $otherSnapshot = $this->snapshotFromHtml($otherHtml, $otherChild->id());

        $childSnapshot = $this->snapshotFromHtml($html, $child->id());

        $response = $this->action($middleware, $session, $childSnapshot, CapabilityCounterChild::class, 'increment');

        self::assertSame(200, $response['status'] ?? null);
        $payload = $this->body($response['data']);
        self::assertSame(2, $payload['snapshot']['state']['count']);

        $parentSnapshots = $payload['parentSnapshots'] ?? [];
        self::assertCount(1, $parentSnapshots);
        self::assertSame($parent->id(), $parentSnapshots[0]['componentId']);
        self::assertSame(2, $parentSnapshots[0]['state']['last_count']);
        self::assertSame(2, $storage->retrieveState($parent->id())['last_count']);
        self::assertSame(0, $storage->retrieveState($otherChild->id())['count']);
        self::assertSame($otherSnapshot['componentId'], $otherChild->id());
    }

    public function test_rotated_session_cannot_reuse_authoritative_component_state(): void
    {
        [$renderer, $storage, $middleware, $session] = $this->services();

        $child = new CapabilityCounterChild(['step' => 2]);
        $html = $renderer->renderComponent($child);
        $snapshot = $this->snapshotFromHtml($html, $child->id());

        $stateBefore = $storage->retrieveState($child->id());

        $session->regenerate();
        $response = $this->action($middleware, $session, $snapshot, CapabilityCounterChild::class, 'increment');

        self::assertSame(403, $response['status']);
        self::assertSame($stateBefore, $storage->retrieveState($child->id()));
    }

    public function test_nested_components_render_with_separate_state_and_properties(): void
    {
        [$renderer, $storage] = $this->services();

        $child = new CapabilityCounterChild(['step' => 4, 'label' => 'Nested']);
        $parent = new CapabilityParent($child);
        $html = $renderer->renderComponent($parent);

        self::assertSame(2, substr_count($html, 'data-firefiber-component="'));
        $childMetadata = $storage->retrieveState($child->id().'::__firefiber_metadata');

        self::assertSame(4, $childMetadata['properties']['step']);
        self::assertSame('Nested', $childMetadata['properties']['label']);
        self::assertSame($parent->id(), $childMetadata['metadata']['parentComponentId']);
        self::assertSame(CapabilityCounterChild::class, $childMetadata['metadata']['componentClass']);
    }

    public function test_form_model_validation_preserves_authenticated_state_and_rejects_bad_values(): void
    {
        $component = new CapabilityFormComponent();

        $component->updateModel(['email' => 'ada@example.com', 'name' => 'Ada', 'age' => '42']);
        self::assertSame('ada@example.com', $component->state()['email']);
        self::assertSame(42, $component->state()['age']);

        try {
            $component->updateModel(['email' => 'not-an-email']);
            self::fail('Expected a validation exception.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('email', $exception->errors());
            self::assertSame('ada@example.com', $component->state()['email']);
        }
    }

    /** @return array{PageRenderer, FileCacheStateStorage, FirefiberMiddleware, \Sahil\Session\Session} */
    private function services(): array
    {
        $application = new CapabilityApplication($this->root);
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
            null,
            new ComponentParentRegistry($storage),
        );
        $middleware = new FirefiberMiddleware(
            new FileCacheReplayStorage($this->root.'/replay'),
            null,
            300,
            'capability-test',
            $storage,
            null,
            0,
            $application,
            $session,
            null,
            $this->root.'/locks',
        );

        return [$renderer, $storage, $middleware, $session];
    }

    private function snapshotFromHtml(string $html, string $componentId): array
    {
        $pattern = '/data-firefiber-component="'.preg_quote($componentId, '/').'"[^>]*data-firefiber-snapshot="([^"]+)"/';
        self::assertSame(1, preg_match($pattern, $html, $matches));
        $snapshot = json_decode(html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($snapshot);

        return $snapshot;
    }

    private function action(
        FirefiberMiddleware $middleware,
        \Sahil\Session\Session $session,
        array $snapshot,
        string $class,
        string $action,
    ): array {
        $body = json_encode([
            'protocolVersion' => '3.1',
            'type' => 'handleAction',
            'requestId' => 'req-capability-'.bin2hex(random_bytes(8)),
            'componentId' => $snapshot['componentId'],
            'componentName' => $class,
            'componentClass' => $class,
            'action' => $action,
            'payload' => [],
            'csrfToken' => $session->token(),
            'signature' => $snapshot['signature'],
            'snapshot' => $snapshot,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $request = Request::fromGlobals([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/firefiber',
            'REQUEST_SCHEME' => 'http',
            'HTTP_HOST' => 'firefiber.test',
            'HTTP_X_FIREFIBER_TARGET' => '1',
            'HTTP_X_CSRF_TOKEN' => $session->token(),
            'CONTENT_TYPE' => 'application/json',
        ], [], [], [], $body);

        $response = $middleware->handle($request, static function (): never {
            throw new RuntimeException('The action endpoint must be intercepted.');
        });

        return ['status' => $response->status(), 'data' => $response->body()];
    }

    private function body(mixed $value): array
    {
        self::assertIsString($value);
        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
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

final class CapabilityCounterChild extends Component
{
    protected array $propertyDefinitions = [
        'step' => ['type' => 'int', 'required' => true, 'min' => 1],
        'label' => ['type' => 'string', 'default' => 'Counter'],
    ];

    protected array $emits = ['count-changed'];

    public function __construct(array $properties = [])
    {
        parent::__construct(['count' => 0], ['increment'], $properties);
    }

    protected function eventPayloadRules(): array
    {
        return [
            'count-changed' => [
                'count' => 'required|integer|min:0',
                'step' => 'required|integer|min:1',
            ],
        ];
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
            throw new UnknownActionException($action, $this->id());
        }

        $count = (int) ($this->state['count'] ?? 0) + (int) $this->property('step');
        $this->updateState(['count' => $count]);
        $this->emit('count-changed', ['count' => $count, 'step' => $this->property('step')]);

        return ['count' => $count];
    }
}

final class CapabilityParent extends Component
{
    protected array $listeners = ['count-changed' => 'onCountChanged'];

    public function __construct(private readonly ?Component $child = null)
    {
        parent::__construct(['last_count' => 0], [], []);
    }

    public function children(): array
    {
        return $this->child === null ? [] : [$this->child];
    }

    public function onCountChanged(array $payload): void
    {
        $this->updateState(['last_count' => (int) ($payload['count'] ?? 0)]);
    }

    public function render(): string
    {
        return '<div><span data-firefiber-bind="last_count">'.(int) ($this->state['last_count'] ?? 0).'</span></div>';
    }

    public function handleAction(string $action, array $payload): array
    {
        throw new UnknownActionException($action, $this->id());
    }
}

final class CapabilityLifecycleComponent extends Component
{
    /** @var list<string> */
    public array $trace = [];

    public function __construct()
    {
        parent::__construct(['count' => 0], ['increment']);
    }

    protected function mount(): void
    {
        $this->trace[] = 'mount';
    }

    protected function booted(): void
    {
        $this->trace[] = 'booted';
    }

    protected function updating(string $action, array $payload): void
    {
        $this->trace[] = 'updating';
    }

    protected function updated(string $action, array $payload, array $result): void
    {
        $this->trace[] = 'updated';
    }

    public function rendering(): void
    {
        $this->trace[] = 'rendering';
    }

    protected function destroyed(): void
    {
        $this->trace[] = 'destroyed';
    }

    public function handleAction(string $action, array $payload): array
    {
        return ['count' => (int) ($this->state['count'] ?? 0) + 1];
    }
}

final class CapabilityFormComponent extends Component
{
    protected array $modelable = ['email', 'name', 'age'];

    public function __construct()
    {
        parent::__construct(['email' => '', 'name' => '', 'age' => 0], ['updateModel']);
    }

    protected function formRules(): array
    {
        return [
            'email' => 'required|email',
            'name' => 'required|string|max:80',
            'age' => 'nullable|integer|min:0|max:130',
        ];
    }

    public function handleAction(string $action, array $payload): array
    {
        return $this->updateModel($payload);
    }
}

final class CapabilityApplication implements ApplicationContract
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
        return new \Sahil\Config\Repository(['app' => ['key' => 'base64:'.base64_encode(str_repeat('c', 32))]]);
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
