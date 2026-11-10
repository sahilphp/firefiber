<?php

declare(strict_types=1);

namespace Sahil\Firefiber\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sahil\Auth\AuthManager;
use Sahil\Auth\RateLimiter;
use Sahil\Config\Repository;
use Sahil\Container\Container;
use Sahil\Contracts\Auth\AuthenticatableContract;
use Sahil\Contracts\Auth\AuthStateContract;
use Sahil\Contracts\Auth\UserProviderContract;
use Sahil\Contracts\Cache\CacheContract;
use Sahil\Contracts\Foundation\ApplicationContract;
use Sahil\Firefiber\Component;
use Sahil\Firefiber\ComponentParentRegistry;
use Sahil\Firefiber\Data\DataTable;
use Sahil\Firefiber\Data\PaginatedResult;
use Sahil\Firefiber\Exceptions\InvalidStateException;
use Sahil\Firefiber\Exceptions\UnknownActionException;
use Sahil\Firefiber\FirefiberSecrets;
use Sahil\Firefiber\Http\FirefiberMiddleware;
use Sahil\Firefiber\Page;
use Sahil\Firefiber\PageRenderer;
use Sahil\Firefiber\Protocol;
use Sahil\Firefiber\SnapshotIntegrity;
use Sahil\Firefiber\Storage\FileCacheReplayStorage;
use Sahil\Firefiber\Storage\FileCacheStateStorage;
use Sahil\Firefiber\Tests\Support\FakeServerRequest;
use Sahil\Firefiber\UrlSync;
use Sahil\Http\Request;
use Sahil\Session\FileSessionDriver;
use Sahil\Session\Session;
use Sahil\Storage\StorageManager;

/**
 * Milestone 3 coverage: server-driven tables, URL-synced state, polling,
 * lazy loading, opt-in server re-rendering, render caching and the secure
 * upload pipeline.
 */
final class AdvancedCapabilityTest extends TestCase
{
    /** Valid 1x1 PNG used for upload fixtures. */
    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private string $root;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/firefiber-advanced-'.bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->root.'/storage/framework/sessions', 0o700, true));
        self::assertTrue(mkdir($this->root.'/storage/framework/firefiber', 0o700, true));
        self::assertTrue(mkdir($this->root.'/state', 0o700, true));
        self::assertTrue(mkdir($this->root.'/replay', 0o700, true));
        self::assertTrue(mkdir($this->root.'/locks', 0o700, true));
        self::assertTrue(mkdir($this->root.'/uploads', 0o700, true));
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_FILES = [];

        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $this->tempFiles = [];

        AdvancedCacheComponent::$renders = 0;
        AdvancedCacheComponent::$generation = '1';
        AdvancedNoCacheComponent::$renders = 0;
        AdvancedCacheParentComponent::$renders = 0;
        AdvancedCacheChildComponent::$renders = 0;

        $this->removeDirectory($this->root);
    }

    // ------------------------------------------------------------------
    // DataTable
    // ------------------------------------------------------------------

    public function test_data_table_paginates_with_clamped_boundaries(): void
    {
        $table = new DataTable(range(1, 55), ['perPage' => 10]);

        $first = $table->paginate([]);
        self::assertInstanceOf(PaginatedResult::class, $first);
        self::assertSame(1, $first->currentPage);
        self::assertSame(6, $first->lastPage);
        self::assertSame(55, $first->total);
        self::assertSame(1, $first->from);
        self::assertSame(10, $first->to);
        self::assertCount(10, $first->data);
        self::assertSame(1, $first->data[0]);
        self::assertFalse($first->hasPrevious());
        self::assertTrue($first->hasNext());
        self::assertTrue($first->hasPages());

        $last = $table->paginate(['page' => 6]);
        self::assertSame(6, $last->currentPage);
        self::assertSame(51, $last->from);
        self::assertSame(55, $last->to);
        self::assertSame([51, 52, 53, 54, 55], $last->data);
        self::assertFalse($last->hasNext());

        // Out-of-range pages clamp to the final page instead of failing.
        $clamped = $table->paginate(['page' => 999]);
        self::assertSame(6, $clamped->currentPage);
        self::assertSame([51, 52, 53, 54, 55], $clamped->data);

        // Non-numeric or negative input falls back to safe minimums.
        $invalid = $table->paginate(['page' => 'abc', 'perPage' => -5]);
        self::assertSame(1, $invalid->currentPage);
        self::assertSame(1, $invalid->perPage);
        self::assertSame(55, $invalid->total);

        // Empty datasets still produce a valid envelope.
        $empty = (new DataTable([], ['perPage' => 10]))->paginate([]);
        self::assertSame(0, $empty->total);
        self::assertSame(1, $empty->currentPage);
        self::assertSame(1, $empty->lastPage);
        self::assertSame(0, $empty->from);
        self::assertSame(0, $empty->to);
        self::assertSame([], $empty->data);
        self::assertFalse($empty->hasPages());
    }

    public function test_data_table_sorts_only_on_allow_listed_fields_with_stable_order(): void
    {
        $rows = [
            ['name' => 'b', 'role' => 'alpha'],
            ['name' => 'a', 'role' => 'x'],
            ['name' => 'a', 'role' => 'y'],
        ];

        $table = new DataTable($rows, [
            'sortableFields' => ['name'],
            'defaultSort' => ['field' => 'name'],
        ]);

        $ascending = $table->paginate(['sort' => 'name', 'direction' => 'asc']);
        self::assertSame('name', $ascending->sort);
        self::assertSame('asc', $ascending->direction);
        self::assertSame(['a', 'a', 'b'], array_column($ascending->data, 'name'));
        // Equal sort keys keep their original order (stable sort).
        self::assertSame(['x', 'y'], array_slice(array_column($ascending->data, 'role'), 0, 2));

        // Fields outside the allow-list fall back to the default sort field,
        // and direction is coerced to a known value.
        $rejected = $table->paginate(['sort' => 'role; DROP TABLE users', 'direction' => 'desc']);
        self::assertSame('name', $rejected->sort);
        self::assertSame('desc', $rejected->direction);
        self::assertSame(['b', 'a', 'a'], array_column($rejected->data, 'name'));

        $coerced = $table->paginate(['sort' => 'name', 'direction' => 'sideways']);
        self::assertSame('asc', $coerced->direction);
    }

    public function test_data_table_filters_searches_and_composes(): void
    {
        $rows = [
            ['name' => 'alice', 'role' => 'admin'],
            ['name' => 'bob', 'role' => 'editor'],
            ['name' => 'carol', 'role' => 'admin'],
        ];

        $table = new DataTable($rows, [
            'perPage' => 10,
            'sortableFields' => ['name'],
            'searchableFields' => ['name'],
            'filters' => ['role' => ['operator' => 'eq']],
            'defaultSort' => ['field' => 'name'],
        ]);

        $filtered = $table->paginate(['filters' => ['role' => 'admin']]);
        self::assertSame(2, $filtered->total);
        self::assertSame(['alice', 'carol'], array_column($filtered->data, 'name'));
        self::assertSame(['role' => 'admin'], $filtered->filters);

        $searched = $table->paginate(['search' => 'bo']);
        self::assertSame(1, $searched->total);
        self::assertSame(['bob'], array_column($searched->data, 'name'));
        self::assertSame('bo', $searched->search);

        $composed = $table->paginate(['filters' => ['role' => 'admin'], 'search' => 'a']);
        self::assertSame(2, $composed->total);
        self::assertSame(['alice', 'carol'], array_column($composed->data, 'name'));

        // Unknown filter keys are ignored rather than trusted.
        $unknownKey = $table->paginate(['filters' => ['bogus' => 'x']]);
        self::assertSame(3, $unknownKey->total);

        // Known keys with non-matching values narrow to an empty result.
        $unknownValue = $table->paginate(['filters' => ['role' => 'ghost']]);
        self::assertSame(0, $unknownValue->total);
        self::assertSame(0, $unknownValue->from);
        self::assertSame(0, $unknownValue->to);
    }

    public function test_data_table_requires_searchable_fields_before_searching(): void
    {
        $table = new DataTable([['name' => 'alice']]);

        $this->expectException(InvalidStateException::class);
        $this->expectExceptionMessage('Search requires at least one searchable field.');

        $table->paginate(['search' => 'ali']);
    }

    // ------------------------------------------------------------------
    // UrlSync
    // ------------------------------------------------------------------

    public function test_url_sync_parses_duplicates_and_rejects_malformed_pairs(): void
    {
        $sync = new UrlSync();

        self::assertSame([], $sync->parse(''));

        $parsed = $sync->parse('?q=hello+world&page=2&filter=a&filter=b&bad%ZZ=1&=empty&novalue');
        self::assertSame(['hello world'], $parsed['q']);
        self::assertSame(['2'], $parsed['page']);
        self::assertSame(['a', 'b'], $parsed['filter']);
        self::assertSame([''], $parsed['novalue']);

        // Parameter names outside the safe pattern are dropped entirely.
        self::assertArrayNotHasKey('bad%ZZ', $parsed);
        self::assertArrayNotHasKey('', $parsed);

        // Raw NUL bytes in values are rejected.
        $withNul = $sync->parse("q=safe\0evil&ok=1");
        self::assertArrayNotHasKey('q', $withNul);
        self::assertSame(['1'], $withNul['ok']);
    }

    public function test_url_sync_serializes_state_into_a_query_string(): void
    {
        $configuration = [
            'search' => ['param' => 'q', 'type' => 'string', 'multiple' => false],
            'page' => ['param' => 'page', 'type' => 'int', 'multiple' => false],
            'filters' => ['param' => 'filter', 'type' => 'string', 'multiple' => true],
            'active' => ['param' => 'active', 'type' => 'bool', 'multiple' => false],
        ];

        $state = [
            'search' => 'a b&c',
            'page' => 3,
            'filters' => ['x', 'y'],
            'active' => false,
            'unmapped' => 'ignored',
        ];

        $sync = new UrlSync();
        $query = $sync->serialize($configuration, $state);

        self::assertSame('q=a+b%26c&page=3&filter=x&filter=y&active=0', $query);

        // Round trip preserves every exposed value.
        self::assertSame(
            ['q' => ['a b&c'], 'page' => ['3'], 'filter' => ['x', 'y'], 'active' => ['0']],
            $sync->parse($query),
        );
    }

    // ------------------------------------------------------------------
    // Component query synchronization
    // ------------------------------------------------------------------

    public function test_query_sync_applies_typed_values_and_rejects_invalid_sensitive_and_unknown_keys(): void
    {
        $component = new AdvancedTableComponent();

        self::assertTrue($component->applyQueryValue('page', '9'));
        self::assertSame(9, $component->state()['page']);

        // The string "0" must become boolean false, not a truthy sentinel.
        self::assertTrue($component->applyQueryValue('active', '0'));
        self::assertFalse($component->state()['active']);

        self::assertTrue($component->applyQueryValue('filters', ['a', 'b']));
        self::assertSame(['a', 'b'], $component->state()['filters']);

        self::assertTrue($component->applyQueryValue('search', 'term'));
        self::assertSame('term', $component->state()['search']);

        // Invalid values never mutate state.
        self::assertFalse($component->applyQueryValue('page', 'not-a-number'));
        self::assertFalse($component->applyQueryValue('active', 'maybe'));
        self::assertFalse($component->applyQueryValue('filters', ['a', ['nested']]));
        self::assertSame(9, $component->state()['page']);
        self::assertFalse($component->state()['active']);
        self::assertSame(['a', 'b'], $component->state()['filters']);

        // Sensitive keys are excluded from the writable configuration and
        // unknown keys are never accepted.
        self::assertFalse($component->applyQueryValue('secret', 'leak'));
        self::assertFalse($component->applyQueryValue('unknown', 'x'));
        self::assertArrayNotHasKey('secret', $component->querySyncConfiguration());
    }

    public function test_query_sync_null_resets_keys_to_constructor_defaults(): void
    {
        $component = new AdvancedTableComponent();

        $component->applyQueryValue('page', '9');
        $component->applyQueryValue('search', 'dirty');
        $component->applyQueryValue('active', '0');
        self::assertSame(9, $component->state()['page']);

        // A null value (the URL parameter disappeared) restores the
        // constructor default for state-backed keys.
        self::assertTrue($component->applyQueryValue('page', null));
        self::assertSame(1, $component->state()['page']);
        self::assertTrue($component->applyQueryValue('search', null));
        self::assertSame('', $component->state()['search']);
        self::assertTrue($component->applyQueryValue('active', null));
        self::assertTrue($component->state()['active']);

        // Unknown keys cannot be reset either.
        self::assertFalse($component->applyQueryValue('missing', null));
    }

    public function test_query_sync_payload_reports_applied_and_rejected_keys(): void
    {
        $component = new AdvancedTableComponent();

        $result = $component->applyQuerySyncPayload([
            'page' => '5',
            'search' => 'term',
            'active' => '0',
            'secret' => 'leak',
            'unknown' => 'x',
        ]);

        sort($result['applied']);
        sort($result['rejected']);

        self::assertSame(['active', 'page', 'search'], $result['applied']);
        self::assertSame(['secret', 'unknown'], $result['rejected']);
        self::assertSame(5, $component->state()['page']);
        self::assertSame('term', $component->state()['search']);
        self::assertFalse($component->state()['active']);
    }

    // ------------------------------------------------------------------
    // PageRenderer: URL hydration, advanced attributes, lazy placeholders
    // ------------------------------------------------------------------

    public function test_renderer_hydrates_url_query_state_and_emits_advanced_attributes(): void
    {
        $harness = $this->harness();
        $html = $this->renderHtml(
            $harness['renderer'],
            [new AdvancedTableComponent()],
            'q=hello&page=3&filter=a&filter=b&active=0&secret=leak&bogus=1',
        );

        $snapshot = $this->snapshotFromHtml($html);
        self::assertSame('hello', $snapshot['state']['search']);
        self::assertSame(3, $snapshot['state']['page']);
        self::assertSame(['a', 'b'], $snapshot['state']['filters']);
        self::assertFalse($snapshot['state']['active']);
        self::assertArrayNotHasKey('secret', $snapshot['state']);
        self::assertArrayNotHasKey('bogus', $snapshot['state']);

        // Query-sync configuration is exposed without sensitive entries.
        $querySync = json_decode((string) $this->attribute($html, 'data-firefiber-query-sync'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['search', 'page', 'filters', 'active'], array_keys($querySync));
        self::assertSame('q', $querySync['search']['param']);
        self::assertSame('int', $querySync['page']['type']);
        self::assertTrue($querySync['filters']['multiple']);

        // Polling, optimistic and upload attributes drive the runtime.
        self::assertSame('5000', $this->attribute($html, 'data-firefiber-poll'));
        self::assertSame('1', $this->attribute($html, 'data-firefiber-poll-paused-hidden'));
        $optimistic = json_decode((string) $this->attribute($html, 'data-firefiber-optimistic'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['active' => true], $optimistic['toggle']);
        self::assertSame('/firefiber/uploads', $this->attribute($html, 'data-firefiber-upload-endpoint'));

        // No browser-visible trusted key material.
        self::assertStringNotContainsString('data-firefiber-key=', $html);
        self::assertStringNotContainsString('data-firefiber-hmac-key=', $html);
        self::assertStringNotContainsString(FirefiberSecrets::masterKey($harness['application']), $html);
    }

    public function test_renderer_renders_only_the_placeholder_for_lazy_components(): void
    {
        $harness = $this->harness();
        $html = $this->renderHtml($harness['renderer'], [new AdvancedLazyComponent()]);

        self::assertSame('visible', $this->attribute($html, 'data-firefiber-lazy'));
        self::assertSame(Component::LOAD_ACTION, $this->attribute($html, 'data-firefiber-lazy-action'));
        self::assertStringContainsString('Deferred panel', $html);
        self::assertStringNotContainsString('Panel content ready', $html);

        // State still persists so a later forced load can hydrate it.
        $snapshot = $this->snapshotFromHtml($html);
        self::assertFalse($snapshot['state']['loaded']);
    }

    // ------------------------------------------------------------------
    // Render caching
    // ------------------------------------------------------------------

    public function test_render_cache_hits_and_isolates_by_session_state_user_and_generation(): void
    {
        AdvancedCacheComponent::$renders = 0;
        AdvancedCacheComponent::$generation = '1';

        $cache = new AdvancedCountingCache();
        $authState = new AdvancedTestAuthState();
        $authState->userId = 'user-1';
        $auth = new AuthManager(new AdvancedTestUserProvider(), $authState, new RateLimiter());

        $harness = $this->harness($cache, $auth);
        $renderer = $harness['renderer'];

        // First render misses and populates the cache.
        $renderer->renderComponent(new AdvancedCacheComponent());
        self::assertSame(1, AdvancedCacheComponent::$renders);
        self::assertSame(1, $cache->puts);

        // Identical identity and state reuse the cached fragment.
        $renderer->renderComponent(new AdvancedCacheComponent());
        self::assertSame(1, AdvancedCacheComponent::$renders);
        self::assertSame(1, $cache->puts);

        // Different state produces a different key.
        $renderer->renderComponent(new AdvancedCacheComponent(['label' => 'beta']));
        self::assertSame(2, AdvancedCacheComponent::$renders);
        self::assertSame(2, $cache->puts);

        // A different user never sees another user's fragment...
        $authState->userId = 'user-2';
        $renderer->renderComponent(new AdvancedCacheComponent(['label' => 'beta']));
        self::assertSame(3, AdvancedCacheComponent::$renders);
        self::assertSame(3, $cache->puts);

        // ...and returning to the first user hits their entry again.
        $authState->userId = 'user-1';
        $renderer->renderComponent(new AdvancedCacheComponent(['label' => 'beta']));
        self::assertSame(3, AdvancedCacheComponent::$renders);
        self::assertSame(3, $cache->puts);

        // A different session misses even with identical state and user.
        $otherSession = new Session(new FileSessionDriver($this->root.'/storage/framework/sessions'));
        $otherSession->startFromRequest(Request::fromGlobals([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/firefiber/advanced',
            'REQUEST_SCHEME' => 'http',
            'HTTP_HOST' => 'firefiber.test',
        ], [], [], [], ''));
        $renderer2 = new PageRenderer(
            $harness['application'],
            $harness['storage'],
            new SnapshotIntegrity(FirefiberSecrets::masterKey($harness['application'])),
            null,
            $otherSession,
            null,
            new ComponentParentRegistry($harness['storage']),
            $cache,
        );
        $renderer2->renderComponent(new AdvancedCacheComponent(['label' => 'beta']));
        self::assertSame(4, AdvancedCacheComponent::$renders);
        self::assertSame(4, $cache->puts);

        // Bumping the generation invalidates every prior entry.
        AdvancedCacheComponent::$generation = '2';
        $renderer->renderComponent(new AdvancedCacheComponent(['label' => 'beta']));
        self::assertSame(5, AdvancedCacheComponent::$renders);
        self::assertSame(5, $cache->puts);
    }

    public function test_render_cache_skips_disabled_components_and_never_reuses_child_markup(): void
    {
        AdvancedNoCacheComponent::$renders = 0;
        AdvancedCacheParentComponent::$renders = 0;
        AdvancedCacheChildComponent::$renders = 0;

        $cache = new AdvancedCountingCache();
        $harness = $this->harness($cache);
        $renderer = $harness['renderer'];

        // Components without a cache configuration are never stored.
        $renderer->renderComponent(new AdvancedNoCacheComponent());
        $renderer->renderComponent(new AdvancedNoCacheComponent());
        self::assertSame(2, AdvancedNoCacheComponent::$renders);
        self::assertSame(0, $cache->puts);

        // A caching parent renders once while children render fresh.
        $parent = new AdvancedCacheParentComponent();
        $first = $renderer->renderComponent($parent);
        $second = $renderer->renderComponent($parent);

        self::assertSame(1, AdvancedCacheParentComponent::$renders);
        self::assertSame(2, AdvancedCacheChildComponent::$renders);
        self::assertSame(1, $cache->puts);

        // Each render ships fresh child markup: a new component id and a
        // freshly signed snapshot, never a cached copy.
        preg_match_all('/data-firefiber-component="([^"]+)"/', $first, $firstIds);
        preg_match_all('/data-firefiber-component="([^"]+)"/', $second, $secondIds);
        self::assertCount(2, $firstIds[1]);
        self::assertCount(2, $secondIds[1]);
        self::assertSame($firstIds[1][0], $secondIds[1][0]);
        self::assertNotSame($firstIds[1][1], $secondIds[1][1]);
    }

    // ------------------------------------------------------------------
    // Middleware built-in actions
    // ------------------------------------------------------------------

    public function test_lazy_load_action_is_restricted_and_returns_fresh_html(): void
    {
        $harness = $this->harness();

        // Components without lazy rendering cannot be force-loaded.
        $plainSnapshot = $this->snapshotFromHtml($this->renderHtml($harness['renderer'], [new AdvancedPlainComponent()]));
        $result = $this->dispatch($harness, $plainSnapshot, Component::LOAD_ACTION, [], AdvancedPlainComponent::class);
        self::assertSame(404, $result['status']);
        $payload = $this->decodeResult($result);
        self::assertSame(Protocol::ERROR_INVALID_ACTION, $payload['errorCode']);

        // A lazy component loads through the built-in action with fresh markup.
        $lazySnapshot = $this->snapshotFromHtml($this->renderHtml($harness['renderer'], [new AdvancedLazyComponent()]));
        $result = $this->dispatch($harness, $lazySnapshot, Component::LOAD_ACTION, [], AdvancedLazyComponent::class);
        self::assertSame(200, $result['status']);
        $payload = $this->decodeResult($result);
        self::assertSame('ok', $payload['status']);
        self::assertTrue($payload['loaded']);
        self::assertStringContainsString('Panel content ready', (string) ($payload['html'] ?? ''));
        self::assertTrue($payload['snapshot']['state']['loaded']);
        self::assertTrue($harness['storage']->retrieveState($lazySnapshot['componentId'])['loaded']);
    }

    public function test_polling_action_is_restricted_and_surfaces_the_continue_flag(): void
    {
        $harness = $this->harness();

        // Non-polling components reject polling requests.
        $plainSnapshot = $this->snapshotFromHtml($this->renderHtml($harness['renderer'], [new AdvancedPlainComponent()]));
        $result = $this->dispatch($harness, $plainSnapshot, Component::POLL_ACTION, [], AdvancedPlainComponent::class);
        self::assertSame(404, $result['status']);
        self::assertSame(Protocol::ERROR_INVALID_ACTION, $this->decodeResult($result)['errorCode']);

        // Polling components tick and report whether to continue.
        $pollerSnapshot = $this->snapshotFromHtml($this->renderHtml($harness['renderer'], [new AdvancedPollingComponent()]));

        $result = $this->dispatch($harness, $pollerSnapshot, Component::POLL_ACTION, [], AdvancedPollingComponent::class);
        self::assertSame(200, $result['status']);
        $payload = $this->decodeResult($result);
        self::assertTrue($payload['result']['polled']);
        self::assertTrue($payload['polling']);
        self::assertSame(1, $payload['snapshot']['state']['ticks']);
        self::assertStringContainsString('data-firefiber-bind="ticks">1<', (string) ($payload['html'] ?? ''));

        // The developer's stop condition stops the browser's loop.
        $result = $this->dispatch($harness, $payload['snapshot'], Component::POLL_ACTION, [], AdvancedPollingComponent::class);
        self::assertSame(200, $result['status']);
        $payload = $this->decodeResult($result);
        self::assertFalse($payload['polling']);
        self::assertSame(2, $payload['snapshot']['state']['ticks']);
    }

    public function test_query_action_is_restricted_and_server_validates_every_value(): void
    {
        $harness = $this->harness();

        // Components without query synchronization reject the action.
        $plainSnapshot = $this->snapshotFromHtml($this->renderHtml($harness['renderer'], [new AdvancedPlainComponent()]));
        $result = $this->dispatch($harness, $plainSnapshot, Component::QUERY_ACTION, ['page' => 2], AdvancedPlainComponent::class);
        self::assertSame(404, $result['status']);
        self::assertSame(Protocol::ERROR_INVALID_ACTION, $this->decodeResult($result)['errorCode']);

        // URL-hydrated table: values flow back through server validation.
        $tableSnapshot = $this->snapshotFromHtml($this->renderHtml($harness['renderer'], [new AdvancedTableComponent()], 'page=4'));
        self::assertSame(4, $tableSnapshot['state']['page']);

        $result = $this->dispatch($harness, $tableSnapshot, Component::QUERY_ACTION, [
            'page' => '9',
            'search' => 'term',
            'secret' => 'leak',
            'bogus' => 'x',
        ], AdvancedTableComponent::class);
        self::assertSame(200, $result['status']);
        $payload = $this->decodeResult($result);
        sort($payload['result']['applied']);
        sort($payload['result']['rejected']);
        self::assertSame(['page', 'search'], $payload['result']['applied']);
        self::assertSame(['bogus', 'secret'], $payload['result']['rejected']);
        self::assertSame(9, $payload['snapshot']['state']['page']);
        self::assertSame('term', $payload['snapshot']['state']['search']);
        self::assertStringContainsString('data-firefiber-bind="page">9<', (string) ($payload['html'] ?? ''));

        // Invalid typed values are rejected without changing state.
        $result = $this->dispatch($harness, $payload['snapshot'], Component::QUERY_ACTION, ['page' => 'not-a-number'], AdvancedTableComponent::class);
        $payload = $this->decodeResult($result);
        self::assertSame(['page'], $payload['result']['rejected']);
        self::assertSame([], $payload['result']['applied']);
        self::assertSame(9, $payload['snapshot']['state']['page']);

        // A null value resets the key to its constructor default.
        $result = $this->dispatch($harness, $payload['snapshot'], Component::QUERY_ACTION, ['page' => null], AdvancedTableComponent::class);
        $payload = $this->decodeResult($result);
        self::assertSame(['page'], $payload['result']['applied']);
        self::assertSame(1, $payload['snapshot']['state']['page']);
    }

    public function test_unauthorized_components_reject_builtin_actions_and_uploads(): void
    {
        $harness = $this->harness();
        $lockedSnapshot = $this->snapshotFromHtml($this->renderHtml($harness['renderer'], [new AdvancedLockedComponent()]));

        // Lazy load re-checks authorization before running load().
        $result = $this->dispatch($harness, $lockedSnapshot, Component::LOAD_ACTION, [], AdvancedLockedComponent::class);
        self::assertSame(403, $result['status']);
        self::assertSame(Protocol::ERROR_UNAUTHORIZED, $this->decodeResult($result)['errorCode']);

        // The query action uses the same "query" ability as render-time hydration.
        $result = $this->dispatch($harness, $lockedSnapshot, Component::QUERY_ACTION, ['page' => 2], AdvancedLockedComponent::class);
        self::assertSame(403, $result['status']);
        self::assertSame(Protocol::ERROR_UNAUTHORIZED, $this->decodeResult($result)['errorCode']);

        // Multipart uploads re-check authorization for the upload ability.
        $result = $this->upload($harness, $lockedSnapshot, AdvancedLockedComponent::class, 'avatar', $this->pngFileEntry());
        self::assertSame(403, $result['status']);
        $payload = $this->decodeResult($result);
        self::assertSame('Upload is not authorized.', $payload['error']);
        self::assertSame(Protocol::ERROR_UNAUTHORIZED, $payload['errorCode']);
    }

    public function test_actions_return_html_only_when_server_rerender_is_enabled(): void
    {
        $harness = $this->harness();

        // rerenderOnAction components carry fresh markup on every action.
        $tableSnapshot = $this->snapshotFromHtml($this->renderHtml($harness['renderer'], [new AdvancedTableComponent()]));
        $result = $this->dispatch($harness, $tableSnapshot, 'jump', ['page' => 5], AdvancedTableComponent::class);
        self::assertSame(200, $result['status']);
        $payload = $this->decodeResult($result);
        self::assertArrayHasKey('html', $payload);
        self::assertStringContainsString('data-firefiber-bind="page">5<', (string) $payload['html']);
        self::assertSame(5, $payload['snapshot']['state']['page']);

        // Default components return state/snapshot only: no render cost.
        $plainSnapshot = $this->snapshotFromHtml($this->renderHtml($harness['renderer'], [new AdvancedPlainComponent()]));
        $result = $this->dispatch($harness, $plainSnapshot, 'increment', [], AdvancedPlainComponent::class);
        self::assertSame(200, $result['status']);
        $payload = $this->decodeResult($result);
        self::assertArrayNotHasKey('html', $payload);
        self::assertSame(1, $payload['snapshot']['state']['count']);
    }

    // ------------------------------------------------------------------
    // Upload pipeline
    // ------------------------------------------------------------------

    public function test_upload_accepts_valid_files_with_randomized_storage_names(): void
    {
        $harness = $this->harness();
        $snapshot = $this->snapshotFromHtml($this->renderHtml($harness['renderer'], [new AdvancedTableComponent()]));

        $result = $this->upload(
            $harness,
            $snapshot,
            AdvancedTableComponent::class,
            'avatar',
            $this->pngFileEntry('../../evil.png'),
        );
        self::assertSame(200, $result['status']);

        $payload = $this->decodeResult($result);
        self::assertSame('ok', $payload['status']);
        self::assertSame($snapshot['componentId'], $payload['componentId']);
        self::assertSame('avatar', $payload['field']);

        $record = $payload['uploads'][0];
        self::assertSame('avatar', $record['field']);
        self::assertSame('evil.png', $record['originalName']);
        self::assertSame('image/png', $record['mimeType']);
        self::assertSame('png', $record['extension']);
        self::assertSame(70, $record['size']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.png$/', $record['storedName']);
        self::assertStringNotContainsString('evil.png', $record['path']);
        self::assertFalse($record['promoted']);
        self::assertFileExists($this->root.'/uploads/'.$record['path']);

        // The upload record is bound to the component and stored server-side.
        $sidecar = $harness['storage']->retrieveState($snapshot['componentId'].'::__firefiber_uploads::'.$record['id']);
        self::assertIsArray($sidecar);
        self::assertSame($snapshot['componentId'], $sidecar['componentId']);
        self::assertFalse($sidecar['promoted']);

        // Upload metadata becomes authoritative component state: the fresh
        // signed snapshot carries it to the browser for rendering.
        $freshState = $payload['snapshot']['state'];
        self::assertNotSame($snapshot['state'], $freshState);
        self::assertCount(1, $freshState['uploads']);
        self::assertSame($record['id'], $freshState['uploads'][0]['id']);
        self::assertSame('evil.png', $freshState['uploads'][0]['originalName']);
        self::assertNotEmpty($payload['snapshot']['signature']);

        // State records cross the trust boundary: session and user binding
        // stays in the server-side sidecar only.
        self::assertArrayHasKey('sessionId', $sidecar);
        self::assertArrayNotHasKey('sessionId', $freshState['uploads'][0]);
        self::assertArrayNotHasKey('userId', $freshState['uploads'][0]);
    }

    public function test_uploads_flow_through_the_framework_pipeline_too(): void
    {
        $harness = $this->harness();
        $snapshot = $this->snapshotFromHtml($this->renderHtml($harness['renderer'], [new AdvancedTableComponent()]));

        $result = $this->upload(
            $harness,
            $snapshot,
            AdvancedTableComponent::class,
            'avatar',
            $this->pngFileEntry(),
            null,
            'framework',
        );
        self::assertSame(200, $result['status']);

        $payload = $this->decodeResult($result);
        self::assertSame('ok', $payload['status']);
        self::assertFileExists($this->root.'/uploads/'.$payload['uploads'][0]['path']);
    }

    public function test_upload_rejects_mismatched_types_oversized_files_and_excess_counts(): void
    {
        $harness = $this->harness();
        $snapshot = $this->snapshotFromHtml($this->renderHtml($harness['renderer'], [new AdvancedTableComponent()]));

        // MIME is detected from content: PHP disguised as .png is refused.
        $phpPath = $this->root.'/upload-disguised.png';
        file_put_contents($phpPath, '<?php echo "pwned";');
        $this->tempFiles[] = $phpPath;
        $result = $this->upload($harness, $snapshot, AdvancedTableComponent::class, 'avatar', [
            'name' => 'photo.png',
            'tmp_name' => $phpPath,
            'error' => UPLOAD_ERR_OK,
            'type' => 'image/png',
            'size' => filesize($phpPath),
        ]);
        self::assertSame(422, $result['status']);
        $payload = $this->decodeResult($result);
        self::assertSame(Protocol::ERROR_VALIDATION, $payload['errorCode']);
        self::assertStringContainsString('type is not permitted', $payload['errors']['avatar'][0]);

        // Size limits come from the server-side definition, not the client.
        $bigPath = $this->root.'/upload-big.png';
        file_put_contents($bigPath, random_bytes(2048));
        $this->tempFiles[] = $bigPath;
        $result = $this->upload($harness, $snapshot, AdvancedTableComponent::class, 'avatar', [
            'name' => 'big.png',
            'tmp_name' => $bigPath,
            'error' => UPLOAD_ERR_OK,
            'type' => 'image/png',
            'size' => filesize($bigPath),
        ]);
        self::assertSame(422, $result['status']);
        $payload = $this->decodeResult($result);
        self::assertStringContainsString('exceeds the maximum allowed size', $payload['errors']['avatar'][0]);

        // Per-definition file counts are enforced server-side.
        $first = $this->pngFileEntry('a.png');
        $second = $this->pngFileEntry('b.png');
        $result = $this->upload($harness, $snapshot, AdvancedTableComponent::class, 'avatar', [
            'name' => ['a.png', 'b.png'],
            'tmp_name' => [$first['tmp_name'], $second['tmp_name']],
            'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
            'type' => ['image/png', 'image/png'],
            'size' => [$first['size'], $second['size']],
        ]);
        self::assertSame(422, $result['status']);
        $payload = $this->decodeResult($result);
        self::assertStringContainsString('Too many uploaded files', $payload['errors']['avatar'][0]);
    }

    public function test_upload_rejects_unknown_fields_and_invalid_csrf(): void
    {
        $harness = $this->harness();
        $snapshot = $this->snapshotFromHtml($this->renderHtml($harness['renderer'], [new AdvancedTableComponent()]));

        // Only declared upload definitions are accepted.
        $result = $this->upload($harness, $snapshot, AdvancedTableComponent::class, 'background', $this->pngFileEntry());
        self::assertSame(404, $result['status']);
        $payload = $this->decodeResult($result);
        self::assertSame('Unknown upload field.', $payload['error']);
        self::assertSame(Protocol::ERROR_INVALID_ACTION, $payload['errorCode']);

        // A mismatched CSRF token never reaches file handling.
        $result = $this->upload($harness, $snapshot, AdvancedTableComponent::class, 'avatar', $this->pngFileEntry(), 'wrong-token');
        self::assertSame(403, $result['status']);
        $payload = $this->decodeResult($result);
        self::assertSame(Protocol::ERROR_UNAUTHORIZED, $payload['errorCode']);
    }

    public function test_promote_and_remove_manage_uploaded_files(): void
    {
        $harness = $this->harness();
        $snapshot = $this->snapshotFromHtml($this->renderHtml($harness['renderer'], [new AdvancedTableComponent()]));

        $upload = $this->upload($harness, $snapshot, AdvancedTableComponent::class, 'avatar', $this->pngFileEntry());
        self::assertSame(200, $upload['status']);
        $uploadPayload = $this->decodeResult($upload);
        $record = $uploadPayload['uploads'][0];
        $stagedPath = $this->root.'/uploads/'.$record['path'];
        self::assertFileExists($stagedPath);

        // The upload changed authoritative state, so later actions send the
        // snapshot returned by the upload response — exactly what the browser
        // stores via rememberSnapshot().
        $snapshot = $uploadPayload['snapshot'];

        // Promotion moves the staged file into the permanent directory.
        $result = $this->dispatch($harness, $snapshot, 'promoteUpload', ['ids' => [$record['id']]], AdvancedTableComponent::class);
        self::assertSame(200, $result['status']);
        $payload = $this->decodeResult($result);
        self::assertSame($record['id'], $payload['result']['promotedUploads'][0]['id']);
        $promotedPath = (string) $payload['result']['promotedUploads'][0]['path'];
        self::assertStringStartsWith('firefiber/permanent/', $promotedPath);
        self::assertFileDoesNotExist($stagedPath);
        self::assertFileExists($this->root.'/uploads/'.$promotedPath);
        self::assertCount(1, $payload['snapshot']['state']['promotedUploads']);

        // The component's own upload list stays in step with promotion.
        self::assertTrue($payload['snapshot']['state']['uploads'][0]['promoted']);
        self::assertSame(0, $payload['snapshot']['state']['uploads'][0]['expiresAt']);

        // Removal deletes the promoted copy and the upload record.
        $result = $this->dispatch($harness, $payload['snapshot'], 'removeUpload', ['ids' => [$record['id']]], AdvancedTableComponent::class);
        self::assertSame(200, $result['status']);
        $payload = $this->decodeResult($result);
        self::assertSame([$record['id']], $payload['result']['removedUploads']);
        self::assertFileDoesNotExist($this->root.'/uploads/'.$promotedPath);
        self::assertNull($harness['storage']->retrieveState($snapshot['componentId'].'::__firefiber_uploads::'.$record['id']));

        // The component's upload list no longer renders a deleted file.
        self::assertSame([], $payload['snapshot']['state']['uploads']);
    }

    public function test_promote_rejects_missing_foreign_and_traversal_targets(): void
    {
        $harness = $this->harness();

        // Unknown or expired upload ids are refused.
        $tableSnapshot = $this->snapshotFromHtml($this->renderHtml($harness['renderer'], [new AdvancedTableComponent()]));
        $result = $this->dispatch($harness, $tableSnapshot, 'promoteUpload', ['ids' => ['does-not-exist']], AdvancedTableComponent::class);
        self::assertSame(422, $result['status']);
        $payload = $this->decodeResult($result);
        self::assertSame(Protocol::ERROR_VALIDATION, $payload['errorCode']);
        self::assertStringContainsString('missing or expired', $payload['errors']['ids'][0]);

        // Upload ids are namespaced per component: another component cannot
        // promote an upload it never accepted.
        $first = $this->upload($harness, $tableSnapshot, AdvancedTableComponent::class, 'avatar', $this->pngFileEntry());
        self::assertSame(200, $first['status']);
        $foreignId = $this->decodeResult($first)['uploads'][0]['id'];

        $other = new AdvancedTableComponent();
        $otherSnapshot = $this->snapshotFromHtml($this->renderHtml($harness['renderer'], [$other]));
        $result = $this->dispatch($harness, $otherSnapshot, 'promoteUpload', ['ids' => [$foreignId]], AdvancedTableComponent::class);
        self::assertSame(422, $result['status']);
        self::assertStringContainsString('missing or expired', $this->decodeResult($result)['errors']['ids'][0]);

        // Records are additionally bound to the component that accepted them.
        $harness['storage']->saveState($otherSnapshot['componentId'].'::__firefiber_uploads::foreign', [
            'id' => 'foreign',
            'componentId' => 'someone-else',
            'field' => 'avatar',
            'disk' => 'local',
            'path' => 'firefiber/uploads/foreign/x.png',
            'storedName' => 'x.png',
            'promoted' => false,
        ], 0);
        $result = $this->dispatch($harness, $otherSnapshot, 'promoteUpload', ['ids' => ['foreign']], AdvancedTableComponent::class);
        self::assertSame(422, $result['status']);
        self::assertStringContainsString('belongs to another component', $this->decodeResult($result)['errors']['ids'][0]);

        // A permanent directory that escapes the storage root is refused.
        $evil = new AdvancedEvilUploadComponent();
        $evilSnapshot = $this->snapshotFromHtml($this->renderHtml($harness['renderer'], [$evil]));
        $upload = $this->upload($harness, $evilSnapshot, AdvancedEvilUploadComponent::class, 'avatar', $this->pngFileEntry());
        self::assertSame(200, $upload['status']);
        $evilId = $this->decodeResult($upload)['uploads'][0]['id'];
        $evilSnapshot = $this->decodeResult($upload)['snapshot'];

        $result = $this->dispatch($harness, $evilSnapshot, 'promoteUpload', ['ids' => [$evilId]], AdvancedEvilUploadComponent::class);
        self::assertSame(422, $result['status']);
        self::assertStringContainsString('not permitted', $this->decodeResult($result)['errors']['destination'][0]);
    }

    // ------------------------------------------------------------------
    // Harness helpers
    // ------------------------------------------------------------------

    /**
     * Build a renderer + middleware pair sharing one persistent container.
     *
     * @return array{
     *     application: AdvancedCapabilityApplication,
     *     container: Container,
     *     session: Session,
     *     storage: FileCacheStateStorage,
     *     renderer: PageRenderer,
     *     middleware: FirefiberMiddleware
     * }
     */
    private function harness(?CacheContract $cache = null, ?AuthManager $auth = null): array
    {
        $application = new AdvancedCapabilityApplication($this->root);
        $container = $application->container();

        $session = new Session(new FileSessionDriver($this->root.'/storage/framework/sessions'));
        $session->startFromRequest(Request::fromGlobals([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/firefiber/advanced',
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
            $auth,
            new ComponentParentRegistry($storage),
            $cache,
        );
        $container->singleton(PageRenderer::class, static fn (): PageRenderer => $renderer);

        if ($auth !== null) {
            $container->singleton(AuthManager::class, static fn (): AuthManager => $auth);
        }

        $uploadStorage = new StorageManager(new Repository([
            'storage' => [
                'default' => 'local',
                'disks' => [
                    'local' => ['driver' => 'local', 'root' => $this->root.'/uploads/', 'public' => false],
                ],
            ],
        ]));

        $middleware = new FirefiberMiddleware(
            new FileCacheReplayStorage($this->root.'/replay'),
            null,
            300,
            'advanced-capability-worker',
            $storage,
            null,
            0,
            $application,
            $session,
            $auth,
            $this->root.'/locks',
            $uploadStorage,
        );

        return compact('application', 'container', 'session', 'storage', 'renderer', 'middleware');
    }

    /** @param list<Component> $components */
    private function page(PageRenderer $renderer, Component ...$components): Page
    {
        $page = new class ($renderer) extends Page {
            /** @var list<Component> */
            public array $items = [];

            public function path(): string
            {
                return '/firefiber/advanced';
            }

            public function title(): string
            {
                return 'Advanced';
            }

            public function components(): array
            {
                return $this->items;
            }
        };

        $page->items = array_values($components);

        return $page;
    }

    /** @param list<Component> $components */
    private function renderHtml(PageRenderer $renderer, array $components, string $queryString = ''): string
    {
        $server = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/firefiber/advanced'.($queryString === '' ? '' : '?'.$queryString),
            'REQUEST_SCHEME' => 'http',
            'HTTP_HOST' => 'firefiber.test',
        ];

        if ($queryString !== '') {
            $server['QUERY_STRING'] = $queryString;
        }

        $request = Request::fromGlobals($server, [], [], [], '');

        return $renderer->render($request, $this->page($renderer, ...$components))->body();
    }

    /** @return array<string, mixed> */
    private function snapshotFromHtml(string $html): array
    {
        preg_match('/data-firefiber-snapshot="([^"]+)"/', $html, $matches);
        self::assertNotEmpty($matches, 'Rendered markup must contain a signed snapshot.');

        $snapshot = json_decode(html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($snapshot);

        return $snapshot;
    }

    private function attribute(string $html, string $name): ?string
    {
        if (preg_match('/'.preg_quote($name, '/').'="([^"]*)"/', $html, $matches) !== 1) {
            return null;
        }

        return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
    }

    /**
     * Dispatch a JSON action through the framework pipeline.
     *
     * @param array<string, mixed> $harness
     * @param array<string, mixed> $payload
     * @return array{status: int, body: string}
     */
    private function dispatch(array $harness, array $snapshot, string $action, array $payload, string $componentClass): array
    {
        $session = $harness['session'];

        $body = json_encode([
            'protocolVersion' => Protocol::PROTOCOL_VERSION,
            'type' => Protocol::TYPE_ACTION,
            'requestId' => 'req-advanced-'.bin2hex(random_bytes(8)),
            'componentId' => $snapshot['componentId'],
            'componentClass' => $componentClass,
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
            'HTTP_HOST' => 'firefiber.test',
            'HTTP_X_FIREFIBER_TARGET' => '1',
            'HTTP_X_CSRF_TOKEN' => $session->token(),
            'CONTENT_TYPE' => 'application/json',
        ], [], [], [], $body);

        $response = $harness['middleware']->handle($request, static function (): never {
            throw new \RuntimeException('The action endpoint must be intercepted.');
        });

        return ['status' => $response->status(), 'body' => $response->body()];
    }

    /**
     * Submit a multipart upload through the PSR-7 or framework pipeline.
     *
     * @param array<string, mixed> $harness
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $files
     * @param array<string, mixed> $overrides
     * @return array{status: int, body: string}
     */
    private function upload(
        array $harness,
        array $snapshot,
        string $componentClass,
        string $field,
        array $files,
        ?string $csrfToken = null,
        string $via = 'psr',
        array $overrides = [],
    ): array {
        $session = $harness['session'];
        $token = $csrfToken ?? $session->token();

        $_POST = array_merge([
            'protocolVersion' => Protocol::PROTOCOL_VERSION,
            'requestId' => 'req-upload-'.bin2hex(random_bytes(8)),
            'componentId' => (string) $snapshot['componentId'],
            'componentClass' => $componentClass,
            'field' => $field,
            'csrfToken' => $token,
            'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ], $overrides);
        $_FILES = [$field => $files];

        if ($via === 'framework') {
            $request = Request::fromGlobals([
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/firefiber/uploads',
                'REQUEST_SCHEME' => 'http',
                'HTTP_HOST' => 'firefiber.test',
                'HTTP_X_FIREFIBER_TARGET' => '1',
                'HTTP_X_CSRF_TOKEN' => $token,
                'CONTENT_TYPE' => 'multipart/form-data; boundary=fakeboundary',
            ], [], ['firefiber_csrf' => $session->token()], $_POST, '');

            $response = $harness['middleware']->handle($request, static function (): never {
                throw new \RuntimeException('The upload endpoint must be intercepted.');
            });

            return ['status' => $response->status(), 'body' => $response->body()];
        }

        $request = new FakeServerRequest('', [
            'X-Firefiber-Target' => '1',
            'X-Csrf-Token' => $token,
            'Content-Type' => 'multipart/form-data; boundary=fakeboundary',
        ], ['firefiber_csrf' => $session->token()]);

        $response = $harness['middleware']->process($request, new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new \RuntimeException('The upload endpoint must be intercepted.');
            }
        });

        return ['status' => $response->getStatusCode(), 'body' => (string) $response->getBody()];
    }

    /**
     * @param array{status: int, body: string} $result
     * @return array<string, mixed>
     */
    private function decodeResult(array $result): array
    {
        $decoded = json_decode($result['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /** @return array<string, mixed> */
    private function pngFileEntry(string $name = 'photo.png'): array
    {
        $path = $this->root.'/upload-'.bin2hex(random_bytes(4)).'.png';
        file_put_contents($path, base64_decode(self::PNG_BASE64, true));
        $this->tempFiles[] = $path;

        return [
            'name' => $name,
            'tmp_name' => $path,
            'error' => UPLOAD_ERR_OK,
            'type' => 'image/png',
            'size' => filesize($path),
        ];
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

// ---------------------------------------------------------------------
// Test components
// ---------------------------------------------------------------------

/** Table-style component exercising query sync, polling, optimistic UI and uploads. */
final class AdvancedTableComponent extends Component
{
    protected array $querySync = [
        'search' => ['param' => 'q', 'type' => 'string'],
        'page' => ['param' => 'page', 'type' => 'int'],
        'filters' => ['param' => 'filter', 'type' => 'string', 'multiple' => true],
        'active' => ['param' => 'active', 'type' => 'bool'],
        'secret' => ['param' => 'secret', 'sensitive' => true],
    ];

    protected int $pollIntervalMs = 5000;

    protected bool $rerenderOnAction = true;

    protected array $optimisticActions = ['toggle' => ['active' => true]];

    protected array $uploadDefinitions = [
        'avatar' => ['maxSize' => 1024, 'maxFiles' => 1, 'allowedExtensions' => ['png']],
    ];

    public function __construct()
    {
        parent::__construct(['search' => '', 'page' => 1, 'filters' => [], 'active' => true], ['toggle', 'jump']);
    }

    public function render(): string
    {
        return '<div><span data-firefiber-bind="page">'.$this->state['page'].'</span>'
            .'<span data-firefiber-bind="search">'.htmlspecialchars((string) $this->state['search'], ENT_QUOTES, 'UTF-8').'</span>'
            .'<button type="button" fiber:click="toggle">Toggle</button></div>';
    }

    public function handleAction(string $action, array $payload): array
    {
        if ($action === 'jump') {
            $this->updateState(['page' => max(1, (int) ($payload['page'] ?? 1))]);

            return ['page' => $this->state['page']];
        }

        if ($action === 'toggle') {
            $this->updateState(['active' => !$this->state['active']]);

            return ['active' => $this->state['active']];
        }

        throw new UnknownActionException($action, $this->id());
    }
}

/** Lazily rendered component whose load() reveals real content. */
final class AdvancedLazyComponent extends Component
{
    protected bool $lazy = true;

    protected string $lazyTrigger = 'visible';

    protected string $lazyPlaceholder = '<p class="firefiber-lazy-placeholder">Deferred panel loading…</p>';

    public function __construct()
    {
        parent::__construct(['loaded' => false], []);
    }

    public function load(): void
    {
        $this->updateState(['loaded' => true]);
    }

    public function render(): string
    {
        return '<p>'.($this->state['loaded'] ? 'Panel content ready' : 'idle').'</p>';
    }

    public function handleAction(string $action, array $payload): array
    {
        throw new UnknownActionException($action, $this->id());
    }
}

/** Plain component with no advanced features (guards built-in 404s). */
final class AdvancedPlainComponent extends Component
{
    public function __construct()
    {
        parent::__construct(['count' => 0], ['increment']);
    }

    public function render(): string
    {
        return '<div><span data-firefiber-bind="count">'.$this->state['count'].'</span></div>';
    }

    public function handleAction(string $action, array $payload): array
    {
        if ($action !== 'increment') {
            throw new UnknownActionException($action, $this->id());
        }

        $this->updateState(['count' => (int) $this->state['count'] + 1]);

        return ['count' => $this->state['count']];
    }
}

/** Polling component with a developer-controlled stop condition. */
final class AdvancedPollingComponent extends Component
{
    protected int $pollIntervalMs = 1000;

    public function __construct()
    {
        parent::__construct(['ticks' => 0], []);
    }

    public function poll(): void
    {
        $this->updateState(['ticks' => (int) $this->state['ticks'] + 1]);
    }

    public function shouldContinuePolling(): bool
    {
        return (int) $this->state['ticks'] < 2;
    }

    public function render(): string
    {
        return '<span data-firefiber-bind="ticks">'.$this->state['ticks'].'</span>';
    }

    public function handleAction(string $action, array $payload): array
    {
        throw new UnknownActionException($action, $this->id());
    }
}

/** Component whose authorize() always fails (403 coverage). */
final class AdvancedLockedComponent extends Component
{
    protected bool $lazy = true;

    protected array $querySync = ['page' => ['param' => 'page', 'type' => 'int']];

    protected array $uploadDefinitions = [
        'avatar' => ['maxSize' => 1024, 'maxFiles' => 1, 'allowedExtensions' => ['png']],
    ];

    public function __construct()
    {
        parent::__construct(['page' => 1], []);
    }

    public function authorize(?AuthenticatableContract $user, ?string $action = null): bool
    {
        return false;
    }

    public function render(): string
    {
        return '<p>locked</p>';
    }

    public function handleAction(string $action, array $payload): array
    {
        throw new UnknownActionException($action, $this->id());
    }
}

/** Upload definition that tries to escape the storage root on promotion. */
final class AdvancedEvilUploadComponent extends Component
{
    protected array $uploadDefinitions = [
        'avatar' => [
            'maxSize' => 1024,
            'maxFiles' => 1,
            'allowedExtensions' => ['png'],
            'permanentDirectory' => '../../escape',
        ],
    ];

    public function __construct()
    {
        parent::__construct([], []);
    }

    public function render(): string
    {
        return '<p>evil-upload</p>';
    }

    public function handleAction(string $action, array $payload): array
    {
        throw new UnknownActionException($action, $this->id());
    }
}

/** Cacheable fragment with a static render counter and generation switch. */
final class AdvancedCacheComponent extends Component
{
    public static int $renders = 0;

    public static string $generation = '1';

    public function __construct(array $state = ['label' => 'alpha'])
    {
        parent::__construct($state, []);
        $this->cacheConfiguration = ['ttl' => 60, 'generation' => self::$generation];
    }

    public function render(): string
    {
        self::$renders++;

        return '<p class="cache-fragment">'.htmlspecialchars((string) $this->state['label'], ENT_QUOTES, 'UTF-8').'</p>';
    }

    public function handleAction(string $action, array $payload): array
    {
        throw new UnknownActionException($action, $this->id());
    }
}

/** Component without a cache configuration (must never be stored). */
final class AdvancedNoCacheComponent extends Component
{
    public static int $renders = 0;

    public function __construct()
    {
        parent::__construct(['label' => 'x'], []);
    }

    public function render(): string
    {
        self::$renders++;

        return '<p>no-cache</p>';
    }

    public function handleAction(string $action, array $payload): array
    {
        throw new UnknownActionException($action, $this->id());
    }
}

/** Child fragment that must always render fresh (never from cache). */
final class AdvancedCacheChildComponent extends Component
{
    public static int $renders = 0;

    public function __construct()
    {
        parent::__construct(['n' => 1], []);
    }

    public function render(): string
    {
        self::$renders++;

        return '<span class="child">child-'.$this->state['n'].'</span>';
    }

    public function handleAction(string $action, array $payload): array
    {
        throw new UnknownActionException($action, $this->id());
    }
}

/** Caching parent that always returns a fresh child instance. */
final class AdvancedCacheParentComponent extends Component
{
    public static int $renders = 0;

    public function __construct()
    {
        parent::__construct(['label' => 'parent'], []);
        $this->cacheConfiguration = ['ttl' => 60];
    }

    /** @return list<Component> */
    public function children(): array
    {
        return [new AdvancedCacheChildComponent()];
    }

    public function render(): string
    {
        self::$renders++;

        return '<div class="parent">'.$this->state['label'].'</div>';
    }

    public function handleAction(string $action, array $payload): array
    {
        throw new UnknownActionException($action, $this->id());
    }
}

// ---------------------------------------------------------------------
// Support doubles
// ---------------------------------------------------------------------

/** Application stub whose container persists across calls (renderer lookup). */
final class AdvancedCapabilityApplication implements ApplicationContract
{
    private readonly Container $container;

    public function __construct(private readonly string $basePath)
    {
        $this->container = new Container();
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

    public function container(): Container
    {
        return $this->container;
    }

    public function environment(): \Sahil\Support\Environment
    {
        return new \Sahil\Support\Environment([]);
    }

    public function config(): Repository
    {
        return new Repository(['app' => ['key' => 'base64:'.base64_encode(str_repeat('a', 32))]]);
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

/** In-memory cache that counts writes so tests can prove hits and misses. */
final class AdvancedCountingCache implements CacheContract
{
    /** @var array<string, mixed> */
    public array $entries = [];

    public int $puts = 0;

    public function get(string $key): mixed
    {
        return $this->entries[$key] ?? null;
    }

    public function put(string $key, mixed $value, int $ttl = 0): bool
    {
        $this->entries[$key] = $value;
        $this->puts++;

        return true;
    }

    public function add(string $key, mixed $value, int $ttl = 0): bool
    {
        if ($this->has($key)) {
            return false;
        }

        return $this->put($key, $value, $ttl);
    }

    public function increment(string $key, int $amount = 1): mixed
    {
        return null;
    }

    public function decrement(string $key, int $amount = 1): mixed
    {
        return null;
    }

    public function forget(string $key): bool
    {
        unset($this->entries[$key]);

        return true;
    }

    public function flush(): bool
    {
        $this->entries = [];
        $this->puts = 0;

        return true;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->entries);
    }

    public function ttL(string $key): mixed
    {
        return null;
    }
}

final class AdvancedTestUser implements AuthenticatableContract
{
    public function __construct(private readonly string $userId)
    {
    }

    public function getAuthIdentifier(): int|string
    {
        return $this->userId;
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }
}

final class AdvancedTestAuthState implements AuthStateContract
{
    public ?string $userId = null;

    public function user(): ?AuthenticatableContract
    {
        return $this->userId === null ? null : new AdvancedTestUser($this->userId);
    }

    public function id(): int|string|null
    {
        return $this->userId;
    }

    public function check(): bool
    {
        return $this->userId !== null;
    }

    public function guest(): bool
    {
        return $this->userId === null;
    }

    public function setUser(AuthenticatableContract $user): void
    {
        $this->userId = (string) $user->getAuthIdentifier();
    }

    public function clear(): void
    {
        $this->userId = null;
    }

    public function remember(AuthenticatableContract $user, string $token): void
    {
    }

    public function retrieveRememberedUser(string $token): ?AuthenticatableContract
    {
        return null;
    }

    public function forgetRememberToken(AuthenticatableContract $user): void
    {
    }
}

final class AdvancedTestUserProvider implements UserProviderContract
{
    public function retrieveByIdentifier(int|string $identifier): ?AuthenticatableContract
    {
        return null;
    }

    public function retrieveByCredentials(array $credentials): ?AuthenticatableContract
    {
        return null;
    }

    public function validateCredentials(AuthenticatableContract $user, array $credentials): bool
    {
        return false;
    }

    public function rehashPasswordIfRequired(AuthenticatableContract $user, string $password, array $options): void
    {
    }
}
