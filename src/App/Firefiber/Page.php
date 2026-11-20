<?php

declare(strict_types=1);

namespace Sahil\Firefiber;

use Sahil\Contracts\Routing\MountablePageContract;
use Sahil\Http\Request;
use Sahil\Http\Response;

/**
 * A first-class, route-mountable Firefiber page.
 *
 * A page is a full-screen reactive component: it owns its own state,
 * actions, forms and lifecycle, and mounts directly on the shared router
 * through `Link::firefiber('/posts/{id}', ShowPost::class)` — no
 * controller is involved.
 *
 * Lifecycle:
 *
 *   - Initial `GET` — the container builds the page, `mount($params,
 *     $request)` receives the matched route parameters, then the page is
 *     rendered once with a signed snapshot and server-side state.
 *   - Action request — the page is re-created by {@see newForHydration()},
 *     its state is restored by {@see Component::hydrate()} and `booted()`
 *     runs; `mount()` does NOT run again. Declared actions, `fiber:model`
     * bindings and form validation then behave exactly like a component's.
 *
 * Public properties are synchronized state: declare them on the subclass
 * and list them in `$modelable`/`$actions`/`$querySync` to control what the
 * browser may write or invoke. Protected/private members are never
 * client-controlled.
 */
abstract class Page extends Component implements MountablePageContract
{
    /**
     * @var array<string, scalar>
     */
    private array $routeParams = [];

    public function __construct(
        protected readonly PageRenderer $renderer,
        array $initialState = [],
        array $actions = [],
        array $properties = [],
    ) {
        $this->id = $this->generateId();
        // Property defaults declared on the subclass (`protected array
        // $state = [...]`, `protected array $actions = [...]`) are the
        // natural page API — explicit constructor arguments only override
        // them when actually provided.
        $this->state = $initialState === [] ? $this->state : $initialState;
        $this->initialState = $this->state;

        if ($actions !== []) {
            $this->actions = $actions;
        }

        $this->initializeProperties($properties);
        // mount() is intentionally deferred: the initial-mount hook needs
        // route parameters, which only exist once the route is dispatched.
        // Hydration paths must not re-run it either.
    }

    /**
     * Re-create the page for an action request via the container, so
     * constructor dependencies (PageRenderer, application services)
     * resolve the same way as on the initial request. mount() is not run:
     * state restoration happens through hydrate(), then booted().
     */
    public static function newForHydration(): static
    {
        self::$hydrationInProgress = true;

        try {
            /** @var static */
            return \app()->container()->make(static::class);
        } finally {
            self::$hydrationInProgress = false;
        }
    }

    /**
     * Absolute HTTP path used when the page mounts through
     * `Link::firefiber(ThePage::class)` without an explicit path.
     */
    public function path(): string
    {
        return '/';
    }

    /**
     * Human-readable page title, also used for <title> by default.
     */
    abstract public function title(): string;

    /**
     * Reactive components composed inside this page. Rendered as children
     * of the page root with their own signed snapshots.
     *
     * @return list<Component>
     */
    abstract public function components(): array;

    /**
     * Page children are the page's composed components.
     *
     * @return list<Component>
     */
    public function children(): array
    {
        return $this->components();
    }

    /**
     * Layout name wrapping the page, or null for the default layout.
     * Pages render their `view()` content inside this layout through the
     * application's normal view conventions.
     */
    public function layout(): ?string
    {
        return null;
    }

    /**
     * Content view name for the page body, or null to use the package's
     * document fallback.
     */
    public function view(): ?string
    {
        return null;
    }

    /**
     * Optional content partial re-rendered inside the page's reactive root
     * when the page opts into `rerenderOnAction`. The browser swaps the
     * root element's innerHTML, so this must be the markup INSIDE the root
     * — typically a shared include also used by `view()`. Composed
     * components are always appended after this content.
     */
    public function contentView(): ?string
    {
        return null;
    }

    /**
     * Initial-mount lifecycle hook. Runs exactly once, on the initial GET
     * request that routed to this page — never on subsequent action
     * requests (those hydrate state instead). Receives the matched route
     * parameters from `Link::firefiber('/posts/{id}', ...)`.
     *
     * @param array<string, scalar> $params
     */
    protected function mount(array $params = [], ?Request $request = null): void
    {
        // Component::__construct compatibility: pages defer this to show().
    }

    /**
     * Route parameters captured during the initial mount. Empty on
     * hydrated action requests — state serialized at mount time is the
     * authoritative record from then on.
     *
     * @return array<string, scalar>
     */
    final public function routeParams(): array
    {
        return $this->routeParams;
    }

    /**
     * Page fragment used for server-driven re-renders. Page subclasses may
     * override to return a different markup convention.
     */
    public function render(): string
    {
        return $this->renderer->renderPageFragment($this);
    }

    /**
     * Restore the page for an action request: state (including the route
     * parameters captured at initial mount) comes back from the signed
     * server-side record, then `booted()` runs. mount() never re-runs here.
     */
    public function hydrate(string $id, array $state): static
    {
        parent::hydrate($id, $state);

        $route = $this->state['__route'] ?? null;

        if (is_array($route)) {
            $this->routeParams = $route;
        }

        return $this;
    }

    /**
     * @param array<string, scalar> $params
     */
    public function show(Request $request, array $params = []): Response
    {
        $this->routeParams = $params;

        // Route parameters captured at mount become part of the page's
        // authoritative state so hydrated requests know which route
        // produced this page.
        $this->state['__route'] = $params;
        $this->initialState['__route'] = $params;

        $this->mount($params, $request);

        return $this->renderer->render($request, $this);
    }
}
