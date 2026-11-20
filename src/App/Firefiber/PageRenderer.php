<?php

declare(strict_types=1);

namespace Sahil\Firefiber;

use RuntimeException;
use Sahil\Auth\AuthManager;
use Sahil\Console\Generator\ApplicationPaths;
use Sahil\Contracts\Cache\CacheContract;
use Sahil\Contracts\Foundation\ApplicationContract;
use Sahil\Contracts\Session\SessionContract;
use Sahil\Contracts\View\ViewContract;
use Sahil\Firefiber\Protocol;
use Sahil\Firefiber\Storage\StateStorageInterface;
use Sahil\Http\Request;
use Sahil\Http\Response;

/**
 * Renders discoverable pages and their reactive components.
 *
 * Every component is snapshotted before HTML reaches the browser. The state
 * is persisted server-side, the snapshot is signed by the server-only master
 * key, and page markup receives no client signing secret. Action requests
 * are authenticated through the Sahil session, CSRF token, and server-held
 * state; a browser-visible request signature is never treated as authority.
 */
final class PageRenderer
{
    /**
     * Components currently being rendered, keyed by object id.
     *
     * A component that reaches itself through `children()` would otherwise
     * recurse until PHP exhausts the stack; nesting must be a tree.
     *
     * @var array<int, string>
     */
    private array $rendering = [];

    /**
     * Style keys already emitted during this render — dedupes component
     * `<style>` tags across nested renders.
     *
     * @var array<string, true>
     */
    private array $emittedStyleKeys = [];

    /** True while `render()` is composing a full page document. */
    private bool $composing = false;

    public function __construct(
        private readonly ApplicationContract $application,
        private readonly StateStorageInterface $stateStorage,
        private readonly SnapshotIntegrity $snapshotIntegrity,
        private readonly ?ViewContract $views = null,
        private readonly ?SessionContract $session = null,
        private readonly ?AuthManager $auth = null,
        private readonly ?ComponentParentRegistry $parentRegistry = null,
        private readonly ?CacheContract $cache = null,
    ) {
    }

    public function render(Request $request, Page $page, array $extra = []): Response
    {
        // Style dedupe is scoped to one page render — a long-lived renderer
        // serving another page must emit that page's tags again.
        $this->emittedStyleKeys = [];
        $this->composing = true;
        $query = $this->queryValues($request);

        try {
            // The page is itself the outermost component: its snapshot, state
            // record and trusted metadata hydrate future action requests exactly
            // like an embedded component's do.
            $this->applyQuerySync($page, $query);
            $metadata = $this->persistComponent($page, null);

            $rendered = [];

            foreach ($page->components() as $component) {
                if (!$component instanceof Component) {
                    throw new RuntimeException('Page components must extend Sahil\\Firefiber\\Component.');
                }

                $rendered[] = $this->renderComponent($component, $page, $query);
            }

            $attributes = $this->rootAttributes($page, $metadata);
            $view = $page->view();

            if ($view === null && $this->views !== null) {
                $candidate = $this->conventionalView($page);
                $view = $candidate !== null && $this->views->exists($candidate) ? $candidate : null;
            }

            if ($view !== null && $this->views !== null) {
                $data = array_merge([
                    'app_name' => (string) $this->application->config()->get('app.name', 'SahilPHP'),
                    'page_title' => $page->title(),
                    'layout' => $page->layout(),
                ], $extra);
                $data['components'] = $rendered;
                $data['firefiber_components'] = $rendered;
                // The page's reactive root attributes (`data-firefiber-*`
                // including the signed snapshot). The template emits these on
                // its root element — `<main {!! firefiber_attributes !!}>` —
                // so browser actions target the page itself.
                $data['firefiber_attributes'] = $attributes;
                $data['firefiber_page'] = $page;

                $markup = $this->views->render($view, $data);

                return Response::html($this->injectStyles($markup, $this->styleTags($page)));
            }

            return Response::html($this->document($page, $rendered, $attributes));
        } finally {
            $this->composing = false;
        }
    }

    /**
     * Render a page's inner markup for server-driven re-renders (pages that
     * opt into `rerenderOnAction`). The browser swaps the page root's
     * innerHTML, so the fragment is the content inside the root element —
     * `contentView()` when the page declares one, otherwise its composed
     * components.
     */
    public function renderPageFragment(Page $page): string
    {
        // The page's own content only: renderComponentInner() appends
        // composed components itself, so rendering them here too would
        // duplicate every child with fresh ids.
        $contentView = $page->contentView();

        if ($contentView !== null && $this->views !== null && $this->views->exists($contentView)) {
            return $this->styleTags($page, true).$this->views->render($contentView, [
                'app_name' => (string) $this->application->config()->get('app.name', 'SahilPHP'),
                'page_title' => $page->title(),
                'firefiber_page' => $page,
            ]);
        }

        return $this->styleTags($page, true);
    }

    /**
     * Persist the component's state + trusted metadata server-side, exactly
     * as renderComponentInstance does, and return the metadata used for the
     * snapshot signature.
     *
     * @return array<string, mixed>
     */
    private function persistComponent(Component $component, ?Component $parent): array
    {
        $metadata = [
            'componentClass' => $component::class,
            'parentComponentId' => $parent?->id(),
            'parentComponentClass' => $parent !== null ? $parent::class : null,
        ];

        if ($this->session !== null && $this->session->isStarted()) {
            $metadata['sessionId'] = $this->session->id();
        }

        $userId = $this->userId();
        if ($userId !== null && $userId !== '') {
            $metadata['userId'] = $userId;
        }

        $component->setTrustedMetadata($metadata);

        if (!$this->stateStorage->saveState($component->id(), $component->state(), 0)) {
            throw new RuntimeException(sprintf('Unable to persist Firefiber component [%s] state.', $component->id()));
        }

        if (!$this->stateStorage->saveState($component->id().'::__firefiber_metadata', [
            'properties' => $component->properties(),
            'metadata' => $component->trustedMetadata(),
        ], 0)) {
            throw new RuntimeException(sprintf('Unable to persist Firefiber component [%s] metadata.', $component->id()));
        }

        return $metadata;
    }

    /**
     * Root-element attributes (`data-firefiber-*`) for the page's reactive
     * root, including the signed snapshot. Emitted on the page's root
     * element inside templates via `{!! firefiber_attributes !!}`.
     *
     * @param array<string, mixed> $metadata
     */
    private function rootAttributes(Page $page, array $metadata): string
    {
        $snapshot = $page->snapshot(
            $this->snapshotIntegrity,
            time(),
            Protocol::PROTOCOL_VERSION,
            $metadata['sessionId'] ?? null,
            $metadata['userId'] ?? null,
            $page::class,
        );

        $snapshotJson = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return sprintf(
            'class="firefiber-page" data-firefiber-component="%s" data-firefiber-component-name="%s" data-firefiber-snapshot="%s" data-firefiber-endpoint="%s" data-firefiber-csrf="%s" data-fx="%s"%s',
            self::escape($page->id()),
            self::escape($page::class),
            self::escape($snapshotJson),
            self::escape($this->actionEndpoint()),
            self::escape($this->csrfToken()),
            self::escape($page->styleScope()),
            $this->advancedAttributes($page, $page->lazyConfiguration(), $snapshotJson),
        );
    }

    /**
     * Component styles, rendered once per scope per page render.
     *
     * `styles()`/`styleFile()` output is scoped beneath the component's
     * `[data-fx]` attribute; `globalStyles()` passes through unscoped.
     * Tags carry `data-firefiber-style` keys so the browser runtime can
     * dedupe them across reactive innerHTML swaps.
     */
    private function styleTags(Component $component, bool $force = false): string
    {
        $collected = $component->collectedStyles();
        $tags = '';

        if ($collected['scoped'] !== '') {
            $key = $component->styleScope();

            if ($force || !isset($this->emittedStyleKeys[$key])) {
                $this->emittedStyleKeys[$key] = true;
                $scoped = \Sahil\Firefiber\Styles\ScopedStylesheet::scope(
                    $collected['scoped'],
                    '[data-fx="'.$key.'"]',
                );
                $tags .= '<style data-firefiber-style="'.self::escape($key).'">'.$scoped.'</style>';
            }
        }

        if ($collected['global'] !== '') {
            $key = 'global:'.$component->styleScope();

            if ($force || !isset($this->emittedStyleKeys[$key])) {
                $this->emittedStyleKeys[$key] = true;
                $tags .= '<style data-firefiber-style="'.self::escape($key).'">'.$collected['global'].'</style>';
            }
        }

        return $tags;
    }

    /**
     * Inject `<style>` tags into a rendered page document — inside `<head>`
     * when present, otherwise at the top of the markup.
     */
    private function injectStyles(string $markup, string $tags): string
    {
        if ($tags === '') {
            return $markup;
        }

        $replaced = preg_replace('/<\/head\s*>/i', $tags.'</head>', $markup, 1, $count);

        if ($replaced !== null && $count > 0) {
            return $replaced;
        }

        return $tags.$markup;
    }

    /**
     * The page's conventional template name — `App\Firefiber\Pages` classes
     * map to `firefiber.pages.*` (`Admin\UsersPage` → `firefiber.pages.admin.users`)
     * so a page without a `view()` override still finds its template in
     * `resources/views/firefiber/pages`.
     */
    private function conventionalView(Page $page): ?string
    {
        $prefix = ApplicationPaths::for((string) $this->application->basePath())
            ->applicationNamespace().'\\Firefiber\\Pages\\';
        $class = $page::class;

        if (!str_starts_with($class, $prefix)) {
            return null;
        }

        $segments = array_map(static function (string $segment): string {
            $segment = str_ends_with($segment, 'Page') ? substr($segment, 0, -4) : $segment;

            return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $segment));
        }, explode('\\', substr($class, strlen($prefix))));

        return 'firefiber.pages.'.implode('.', $segments);
    }

    public function renderComponent(Component $component, ?Component $parent = null, array $query = [], bool $force = false): string
    {
        $objectId = spl_object_id($component);

        if ($this->rendering === [] && !$this->composing) {
            // A standalone renderComponent starts a fresh composition —
            // nested/page-driven renders keep sharing the dedupe set.
            $this->emittedStyleKeys = [];
        }

        if (isset($this->rendering[$objectId])) {
            throw new RuntimeException(sprintf(
                'Circular Firefiber component nesting detected while rendering [%s].',
                $component::class,
            ));
        }

        $this->rendering[$objectId] = $component::class;

        try {
            return $this->renderComponentInstance($component, $parent, $query, $force);
        } finally {
            unset($this->rendering[$objectId]);
        }
    }

    private function renderComponentInstance(Component $component, ?Component $parent, array $query, bool $force): string
    {
        $this->applyQuerySync($component, $query);

        $metadata = $this->persistComponent($component, $parent);

        if ($parent !== null) {
            $this->parentRegistry?->link($parent, $component);
        }

        $lazy = !$force ? $component->lazyConfiguration() : [];

        if ($lazy !== []) {
            $inner = (string) ($lazy['placeholder'] ?? '<p class="firefiber-lazy-placeholder">Loading…</p>');
        } else {
            $inner = $this->renderComponentInner($component, $query, $metadata);
        }

        $snapshot = $component->snapshot(
            $this->snapshotIntegrity,
            time(),
            Protocol::PROTOCOL_VERSION,
            $metadata['sessionId'] ?? null,
            $metadata['userId'] ?? null,
            $component::class,
        );

        $snapshotJson = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $configuredAttributes = $this->advancedAttributes($component, $lazy, $snapshotJson);

        return sprintf(
            '<section class="firefiber-component" data-firefiber-component="%s" data-firefiber-component-name="%s" data-firefiber-snapshot="%s" data-firefiber-endpoint="%s" data-firefiber-csrf="%s" data-fx="%s"%s>%s%s</section>',
            self::escape($component->id()),
            self::escape($component::class),
            self::escape($snapshotJson),
            self::escape($this->actionEndpoint()),
            self::escape($this->csrfToken()),
            self::escape($component->styleScope()),
            $configuredAttributes,
            $this->styleTags($component),
            $inner,
        );
    }

    /**
     * Render a component's current fragment (own output plus children)
     * without the wrapper section. Used by the action pipeline for lazy
     * loads, polling refreshes and opt-in server re-renders.
     */
    public function renderFragment(Component $component): string
    {
        return $this->styleTags($component, true).$this->renderComponentInner($component, [], $component->trustedMetadata());
    }

    private function renderComponentInner(Component $component, array $query, array $metadata): string
    {
        $component->rendering();

        $cache = $this->componentCache($component, $metadata);
        $fragment = null;

        if ($cache !== null) {
            $cached = $this->cache?->get($cache['key']);

            if (is_string($cached)) {
                $fragment = $cached;
            }
        }

        if ($fragment === null) {
            $fragment = $component->render();

            if ($cache !== null) {
                $this->cache?->put($cache['key'], $fragment, $cache['ttl']);
            }
        }

        // Children are always rendered fresh: their markup embeds signed
        // snapshots with their own component IDs and timestamps, which must
        // never be reused from a shared render cache.
        $inner = $fragment;

        foreach ($component->children() as $child) {
            if (!$child instanceof Component) {
                throw new RuntimeException('Nested components must extend Sahil\\Firefiber\\Component.');
            }

            $inner .= $this->renderComponent($child, $component, $query);
        }

        return $inner;
    }

    private function applyQuerySync(Component $component, array $query): void
    {
        if ($query === []) {
            return;
        }

        $configuration = $component->querySyncConfiguration();
        $auth = $this->resolvedAuth();

        if (!$component->authorize($auth?->guest() ? null : $auth?->user(), 'query')) {
            return;
        }

        foreach ($configuration as $key => $definition) {
            $param = $definition['param'];

            if (!array_key_exists($param, $query)) {
                continue;
            }

            $value = $definition['multiple'] ? $query[$param] : $query[$param][0] ?? null;

            if ($value !== null) {
                $component->applyQueryValue($key, $value);
            }
        }
    }

    private function queryValues(Request $request): array
    {
        $values = [];

        foreach ((new UrlSync())->parse($request->queryString()) as $param => $items) {
            $values[$param] = $items;
        }

        foreach ($request->query() as $param => $value) {
            if (!array_key_exists($param, $values)) {
                $values[$param] = [$value];
            }
        }

        return $values;
    }

    private function advancedAttributes(Component $component, array $lazy, string $snapshotJson): string
    {
        $attributes = [];

        $querySync = $component->querySyncConfiguration();
        if ($querySync !== []) {
            $attributes['data-firefiber-query-sync'] = json_encode($querySync, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $optimistic = $component->optimisticActions();
        if ($optimistic !== []) {
            $attributes['data-firefiber-optimistic'] = json_encode($optimistic, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if ($component->uploadDefinitions() !== []) {
            $attributes['data-firefiber-upload-endpoint'] = $this->uploadEndpoint();
        }

        if ($lazy !== []) {
            $attributes['data-firefiber-lazy'] = (string) ($lazy['trigger'] ?? 'visible');
            $attributes['data-firefiber-lazy-action'] = (string) ($lazy['action'] ?? 'load');
        }

        $polling = $component->pollingConfiguration();
        if ($polling['intervalMs'] > 0) {
            $attributes['data-firefiber-poll'] = (string) $polling['intervalMs'];
            $attributes['data-firefiber-poll-paused-hidden'] = $polling['pausedWhenHidden'] ? '1' : '0';
        }

        if ($attributes === []) {
            return '';
        }

        return implode('', array_map(
            static fn (string $name, string $value): string => sprintf(' %s="%s"', $name, self::escape($value)),
            array_keys($attributes),
            array_values($attributes),
        ));
    }

    private function componentCache(Component $component, array $metadata): ?array
    {
        if ($this->cache === null) {
            return null;
        }

        $configuration = $component->cacheConfiguration();
        $enabled = $configuration['enabled']
            ?? (array_key_exists('ttl', $configuration) || array_key_exists('lifetime', $configuration));

        if (!$enabled) {
            return null;
        }

        $ttl = (int) ($configuration['ttl'] ?? $configuration['lifetime'] ?? 300);

        if ($ttl <= 0) {
            return null;
        }

        // Component IDs are regenerated on every request, so they can never
        // be part of a shareable key. Isolation instead comes from identity,
        // authorization, tenant, property and state dimensions. Developers
        // with render output that embeds the instance ID can opt into the
        // non-sharing `instance` scope.
        $scope = (string) ($configuration['scope'] ?? 'user');
        $identity = array_filter([
            'session' => isset($metadata['sessionId']) ? hash('sha256', (string) $metadata['sessionId']) : null,
            'user' => $metadata['userId'] ?? null,
            'tenant' => $configuration['tenant'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        $keyDimensions = [
            // Bump (or reconfigure) the generation to invalidate every entry
            // for this component; there is no tag support in CacheContract.
            'generation' => (string) ($configuration['generation'] ?? $configuration['version'] ?? '1'),
            'scope' => in_array($scope, ['user', 'instance'], true) ? $scope : 'user',
            'class' => $component::class,
            'state' => $component->state(),
            'properties' => $component->properties(),
            'identity' => $identity,
            'scopes' => array_values((array) ($configuration['scopes'] ?? [])),
        ];

        if ($keyDimensions['scope'] === 'instance') {
            $keyDimensions['id'] = $component->id();
        }

        return [
            'key' => 'firefiber:render:'.hash('sha256', json_encode($keyDimensions, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            'ttl' => $ttl,
        ];
    }

    private function uploadEndpoint(): string
    {
        $path = $this->application->config()->get('firefiber.upload_path', '/firefiber/uploads');

        return is_string($path) && $path !== '' && str_starts_with($path, '/') ? $path : '/firefiber/uploads';
    }

    private function document(Page $page, array $components, string $rootAttributes = ''): string
    {
        $body = implode("\n", $components);

        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<title>'.self::escape($page->title()).'</title>'
            .'<link rel="stylesheet" href="/firefiber/assets/firefiber.min.css">'
            .$this->styleTags($page)
            .'</head><body>'
            .'<main'.$rootAttributes.'><h1>'.self::escape($page->title()).'</h1>'
            .$body
            .'</main>'
            .'<script src="/firefiber/assets/runtime.min.js" defer></script>'
            .'<script defer>document.addEventListener("DOMContentLoaded",function(){if(window.Firefiber){window.Firefiber.boot();}});</script>'
            .'</body></html>';
    }

    private function actionEndpoint(): string
    {
        $endpoint = $this->application->config()->get('firefiber.action_path', '/firefiber');

        return is_string($endpoint) && $endpoint !== '' && str_starts_with($endpoint, '/')
            ? $endpoint
            : '/firefiber';
    }

    private function csrfToken(): string
    {
        if ($this->session === null || !$this->session->isStarted()) {
            return '';
        }

        return $this->session->token();
    }

    private function resolvedAuth(): ?AuthManager
    {
        if ($this->auth !== null) {
            return $this->auth;
        }

        try {
            $container = $this->application->container();

            if (!$container->bound(AuthManager::class)) {
                return null;
            }

            return $container->make(AuthManager::class);
        } catch (\Throwable) {
            // Pages remain renderable when no valid auth binding exists.
            return null;
        }
    }

    private function userId(): ?string
    {
        $auth = $this->resolvedAuth();

        if ($auth === null || $auth->guest()) {
            return null;
        }

        $id = $auth->id();

        return $id === null ? null : (string) $id;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
