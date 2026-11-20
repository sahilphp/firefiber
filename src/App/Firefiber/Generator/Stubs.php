<?php

declare(strict_types=1);

namespace Sahil\Firefiber\Generator;

/**
 * Built-in Firefiber code templates.
 */
final class Stubs
{
    private function __construct()
    {
    }

    public static function component(string $namespace, string $class, string $kind = 'counter'): string
    {
        if ($kind === 'hero') {
            return strtr(<<<'PHP'
<?php

declare(strict_types=1);

namespace {{namespace}};

use Sahil\Firefiber\Component;

/**
 * {{class}}.
 *
 * This starter component is intentionally small: replace its state, actions
 * and markup as the real page requires.
 */
final class {{class}} extends Component
{
    protected array $state = ['message' => 'Build something reactive.'];
    protected array $actions = ['updateMessage'];

    /** @var list<string> */
    protected array $modelable = ['message'];

    public function __construct()
    {
        parent::__construct();
    }

    protected function formRules(): array
    {
        return ['message' => 'required|string|max:255'];
    }

    public function updateMessage(string $message): void
    {
        $this->updateState(['message' => $message]);
    }

    public function render(): string
    {
        $message = htmlspecialchars((string) ($this->state['message'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<div class="firefiber-hero"><h2>Firefiber</h2><p data-firefiber-bind="message">'.$message.'</p>'
            .'<label>Message <input type="text" value="'.$message.'" fiber:model="message"></label>'
            .'<p data-firefiber-error="message"></p></div>';
    }
}
PHP, [
                '{{namespace}}' => $namespace,
                '{{class}}' => $class,
            ]);
        }

        if ($kind === 'stats') {
            return strtr(<<<'PHP'
<?php

declare(strict_types=1);

namespace {{namespace}};

use Sahil\Firefiber\Component;

/**
 * {{class}}.
 *
 * A small interactive counter with secure server-side state.
 */
final class {{class}} extends Component
{
    protected array $propertyDefinitions = [
        'step' => ['type' => 'int', 'default' => 1, 'min' => 1],
    ];

    protected array $emits = ['count-changed'];

    protected array $actions = ['increment', 'decrement'];
    protected array $state = ['count' => 0];

    public function __construct(array $properties = [])
    {
        parent::__construct([], [], $properties);
    }

    /**
     * Event payloads are validated against these rules before dispatch is
     * queued — forged or malformed payloads never reach listeners.
     */
    protected function eventPayloadRules(): array
    {
        return [
            'count-changed' => [
                'count' => 'required|integer',
                'step' => 'required|integer|min:1',
            ],
        ];
    }

    public function increment(): void
    {
        $this->bump(1);
    }

    public function decrement(): void
    {
        $this->bump(-1);
    }

    private function bump(int $direction): void
    {
        $step = (int) $this->property('step');
        $count = (int) ($this->state['count'] ?? 0) + ($direction * $step);
        $this->updateState(['count' => $count]);
        $this->dispatch('count-changed', ['count' => $count, 'step' => $step]);
    }

    public function render(): string
    {
        $count = (int) ($this->state['count'] ?? 0);

        return '<div class="firefiber-stats"><span data-firefiber-bind="count">'.$count.'</span>'
            .'<div><button type="button" fiber:click="increment">Increment</button>'
            .'<button type="button" fiber:click="decrement">Decrement</button></div></div>';
    }
}
PHP, [
                '{{namespace}}' => $namespace,
                '{{class}}' => $class,
            ]);
        }

        return strtr(<<<'PHP'
<?php

declare(strict_types=1);

namespace {{namespace}};

use Sahil\Firefiber\Attributes\On;
use Sahil\Firefiber\Component;

/**
 * {{class}}.
 *
 * This is a small interactive starter demonstrating the component basics:
 * state, allowlisted actions, a scoped stylesheet, an event broadcast and
 * an event listener. Replace the internals as the real component requires.
 */
final class {{class}} extends Component
{
    /**
     * Component state persisted between requests in a signed snapshot.
     */
    protected array $state = ['count' => 0];

    /**
     * Browser-invocable actions — allowlisted public methods on this class.
     */
    protected array $actions = ['increment', 'decrement'];

    /**
     * Events this component may emit (optional allowlist for dispatch()).
     */
    protected array $emits = ['count-changed'];

    /**
     * CSS scoped to this component: rules are rewritten under the
     * component's [data-fx] attribute at render time and emitted once per
     * page. For larger stylesheets return a path from styleFile()
     * instead (resources/css/...).
     */
    protected function styles(): string
    {
        return '.{{class-kebab}} { display: inline-flex; align-items: center; gap: .5rem; }';
    }

    public function __construct()
    {
        parent::__construct();
    }

    public function increment(): void
    {
        $count = (int) ($this->state['count'] ?? 0) + 1;
        $this->updateState(['count' => $count]);

        // Broadcast to every listener in the rendered tree — parents,
        // siblings and the page — plus a `count-changed` CustomEvent and a
        // `firefiber:count-changed` document event in the browser.
        $this->dispatch('count-changed', ['count' => $count]);
    }

    public function decrement(): void
    {
        $count = (int) ($this->state['count'] ?? 0) - 1;
        $this->updateState(['count' => $count]);
        $this->dispatch('count-changed', ['count' => $count]);
    }

    /**
     * Event listener: payload keys map onto parameters by name.
     * dispatchSelf('reset-count') from an action would target only this
     * component; dispatchUp()/dispatchDown() aim at ancestors/descendants.
     */
    #[On('reset-count')]
    public function resetCount(): void
    {
        $this->updateState(['count' => 0]);
    }

    public function render(): string
    {
        $count = (int) ($this->state['count'] ?? 0);

        return '<div class="{{class-kebab}}"><span data-firefiber-bind="count">'.$count.'</span>'
            .'<button type="button" fiber:click="increment">Increment</button>'
            .'<button type="button" fiber:click="decrement">Decrement</button></div>';
    }
}
PHP, [
            '{{namespace}}' => $namespace,
            '{{class}}' => $class,
            '{{class-kebab}}' => strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $class)),
        ]);
    }

    /**
     * Generate an inline component stub.
     * Inline components are rendered directly within a page's components() method
     * and do not have their own dedicated file. They use the Component base class
     * but are designed to be composed into pages rather than generated as standalone
     * files.
     *
     * @param string $namespace The component namespace
     * @param string $class The component class name
     * @return string The inline component PHP source code
     */
    public static function inlineComponent(string $namespace, string $class): string
    {
        return strtr(<<<'PHP'
<?php

declare(strict_types=1);

namespace {{namespace}};

use Sahil\Firefiber\Component;

/**
 * {{class}} - inline component
 *
 * An inline component rendered directly within a page. Insert it with
 * `<fiber:component name="{{class}}" />` or `@component('{{class}}')`.
 */
final class {{class}} extends Component
{
    protected array $state = ['message' => ''];
    protected array $actions = ['updateMessage'];

    public function __construct()
    {
        parent::__construct();
    }

    protected function formRules(): array
    {
        return [];
    }

    public function updateMessage(string $message = ''): void
    {
        $this->updateState(['message' => $message]);
    }

    public function render(): string
    {
        return '<div data-firefiber-bind="message">test</div>';
    }
}
PHP, [
                    '{{namespace}}' => $namespace,
                    '{{class}}' => $class,
                ]);
    }

    /**
     * @param list<array{0: string, 1: string}> $components
     */
    public static function page(
        string $namespace,
        string $class,
        string $route,
        string $view,
        array $components = [],
    ): string {
        $uses = '';
        $instances = '';

        if ($components !== []) {
            $uses .= "\n";
            $instances = "\n        ";

            foreach ($components as [$componentNamespace, $componentClass]) {
                $uses .= sprintf("use %s\\%s;\n", $componentNamespace, $componentClass);
                $instances .= sprintf('new %s(), ', $componentClass);
            }

            $instances = rtrim($instances, ' ')."\n";
        }

        return strtr(<<<'PHP'
<?php

declare(strict_types=1);

namespace {{namespace}};

use Sahil\Firefiber\Attributes\On;
use Sahil\Firefiber\Component;
use Sahil\Firefiber\Page;
use Sahil\Http\Request;
{{uses}}
/**
 * {{class}}.
 *
 * Mount this page on the route table explicitly — add
 * `Link::firefiber('{{route}}', {{class}}::class)` to `routes/web.php`.
 * No controller is involved: the page owns its state, actions and
 * lifecycle, and hydrates itself from a signed snapshot on every
 * subsequent request.
 */
final class {{class}} extends Page
{
    /**
     * Public page state persisted between requests in a signed snapshot.
     */
    protected array $state = ['visits' => 0];

    /**
     * Browser-invocable actions. Only methods listed here may be called
     * from the frontend; lifecycle and internal methods can never be.
     */
    protected array $actions = ['increment'];

    public function path(): string
    {
        return '{{route}}';
    }

    public function title(): string
    {
        return '{{class}}';
    }

    /**
     * Initial-mount hook. Runs exactly once, on the first GET request,
     * with the route's matched parameters — e.g. `$params['id']` for
     * `Link::firefiber('/posts/{id}', ...)`. Later action requests restore
     * the signed state instead; mount() never re-runs.
     *
     * @param array<string, scalar> $params
     */
    protected function mount(array $params = [], ?Request $request = null): void
    {
    }

    /**
     * A page action: `fiber:click="increment"` in the template invokes it.
     * Add method names to `$actions` to expose more.
     */
    public function increment(): void
    {
        $this->updateState(['visits' => (int) ($this->state['visits'] ?? 0) + 1]);
    }

    /**
     * Event listener: `#[On]` methods receive events dispatched by this
     * page's components (dispatch()), by the page itself, or by sibling
     * components. Payload keys map onto parameters by name.
     */
    #[On('visits-reset')]
    public function resetVisits(): void
    {
        $this->updateState(['visits' => 0]);
    }

    /**
     * Page-scoped CSS: rules apply only beneath this page's [data-fx]
     * root. For a shared stylesheet point styleFile() at a file under
     * resources/css/ instead; globalStyles() emits unscoped CSS.
     */
    protected function styles(): string
    {
        return '.{{class-kebab}}-title { margin: 0; }';
    }

    /**
     * @return list<Component>
     */
    public function components(): array
    {
        return [{{instances}}];
    }

    public function view(): ?string
    {
        return '{{view}}';
    }
}
PHP, [
            '{{namespace}}' => $namespace,
            '{{class}}' => $class,
            '{{route}}' => $route,
            '{{view}}' => $view,
            '{{uses}}' => $uses,
            '{{instances}}' => $instances === '' ? '' : substr($instances, 9, -2),
            '{{class-kebab}}' => strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $class)),
        ]);
    }

    public static function pageView(string $class): string
    {
        return strtr(<<<'PHP'
@extends('layouts.app')

@section('title'){{ page_title }}@endsection

@section('content')
{{-- `firefiber_attributes` marks the page's reactive root: it carries the
     signed snapshot the runtime hydrates `fiber:*` directives from. --}}
<section {!! firefiber_attributes !!}>
    <h1 class="{{class-kebab}}-title">{{ page_title }}</h1>

    <button type="button" fiber:click="increment" fiber:loading.attr="disabled">
        Visited <span data-firefiber-bind="visits">0</span> times
    </button>

    @foreach (components as component)
        {!! component !!}
    @endforeach
</section>
@endsection
PHP, [
            '{{class}}' => $class,
        ]);
    }
}
