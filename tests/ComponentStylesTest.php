<?php

declare(strict_types=1);

namespace Sahil\Firefiber\Tests;

use PHPUnit\Framework\TestCase;
use Sahil\Contracts\Session\SessionContract;
use Sahil\Firefiber\Component;
use Sahil\Firefiber\Exceptions\InvalidStateException;
use Sahil\Firefiber\FirefiberServiceProvider;
use Sahil\Firefiber\Page;
use Sahil\Firefiber\PageRenderer;
use Sahil\Firefiber\Styles\ScopedStylesheet;
use Sahil\Foundation\Application;
use Sahil\Http\Request;
use Sahil\Session\FileSessionDriver;
use Sahil\Session\Session;

/**
 * Component-scoped styles: the CSS scoping parser, stable `[data-fx]`
 * scope attributes, per-render style deduplication, stylesheet-file
 * resolution, and opt-in global styles.
 */
final class ComponentStylesTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/firefiber-styles-'.bin2hex(random_bytes(6));
        foreach (['/app', '/config', '/storage/sessions', '/resources/views', '/resources/css/firefiber'] as $dir) {
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
            'HTTP_HOST' => 'styles.test',
        ], [], [], [], ''));
        $application->container()->instance(SessionContract::class, $session);

        $provider = new FirefiberServiceProvider($application);
        $provider->register();
        $provider->boot();

        return $application;
    }

    private function renderComponent(Application $application, Component ...$components): string
    {
        $renderer = $application->container()->make(PageRenderer::class);
        $html = '';

        foreach ($components as $component) {
            $html .= $renderer->renderComponent($component);
        }

        return $html;
    }

    public function test_selectors_are_scoped_under_the_component_attribute(): void
    {
        $css = '.btn { color: red; } .btn:hover > .icon { fill: blue; }';
        $scoped = ScopedStylesheet::scope($css, '[data-fx="fx-test"]');

        self::assertStringContainsString('[data-fx="fx-test"] .btn { color: red; }', $scoped);
        self::assertStringContainsString('[data-fx="fx-test"] .btn:hover > .icon', $scoped);
    }

    public function test_selector_lists_and_complex_selectors_scope_each_branch(): void
    {
        $scoped = ScopedStylesheet::scope(
            '.a, .b + .c:hover { x: y; }',
            '[data-fx="fx-1"]',
        );

        self::assertStringContainsString('[data-fx="fx-1"] .a', $scoped);
        self::assertStringContainsString('[data-fx="fx-1"] .b + .c:hover', $scoped);
    }

    public function test_commas_inside_parens_and_attributes_do_not_split(): void
    {
        $scoped = ScopedStylesheet::scope(
            '.x:not(.a, .b), [title="a,b"] { y: z; }',
            '[data-fx="fx-2"]',
        );

        self::assertStringContainsString('[data-fx="fx-2"] .x:not(.a, .b)', $scoped);
        self::assertStringContainsString('[data-fx="fx-2"] [title="a,b"]', $scoped);
    }

    public function test_media_and_supports_queries_scope_their_inner_rules(): void
    {
        $css = '@media (min-width: 40em) { .card { display: grid; } }'
            .'@supports (display: grid) { .card { gap: 1rem; } }';
        $scoped = ScopedStylesheet::scope($css, '[data-fx="fx-3"]');

        self::assertStringContainsString('@media (min-width: 40em)', $scoped);
        self::assertStringContainsString('[data-fx="fx-3"] .card { display: grid; }', $scoped);
        self::assertStringContainsString('@supports (display: grid)', $scoped);
        self::assertStringContainsString('[data-fx="fx-3"] .card { gap: 1rem; }', $scoped);
    }

    public function test_keyframes_and_font_face_pass_through_unscoped(): void
    {
        $css = '@keyframes pulse { from { opacity: 0; } to { opacity: 1; } }'
            .'@font-face { font-family: X; src: url("x.woff2"); }';
        $scoped = ScopedStylesheet::scope($css, '[data-fx="fx-4"]');

        // `from`/`to` inside keyframes must NOT become scoped selectors.
        self::assertStringContainsString('from { opacity: 0; }', $scoped);
        self::assertStringNotContainsString('[data-fx="fx-4"] from', $scoped);
        self::assertStringNotContainsString('[data-fx="fx-4"] @font-face', $scoped);
        self::assertStringContainsString('src: url("x.woff2");', $scoped);
    }

    public function test_document_roots_map_to_the_scope_itself(): void
    {
        $scoped = ScopedStylesheet::scope(
            'html, body { margin: 0; } :root { --c: red; }',
            '[data-fx="fx-5"]',
        );

        $flat = (string) preg_replace('/\s+/', '', $scoped);
        self::assertStringContainsString('[data-fx="fx-5"],[data-fx="fx-5"]', $flat);
        self::assertStringNotContainsString('html', $flat);
        self::assertStringNotContainsString('[data-fx="fx-5"]:root', $flat);
        self::assertStringContainsString('[data-fx="fx-5"]{--c:red;}', $flat);
    }

    public function test_comments_and_string_contents_are_preserved(): void
    {
        $css = '/* comment with .class and { } */ .x::after { content: "a;b{c}"; }';
        $scoped = ScopedStylesheet::scope($css, '[data-fx="fx-6"]');

        self::assertStringContainsString('/* comment with .class and { } */', $scoped);
        self::assertStringContainsString('content: "a;b{c}";', $scoped);
        self::assertStringContainsString('[data-fx="fx-6"] .x::after', $scoped);
    }

    public function test_scope_identifier_is_stable_and_unique_per_class(): void
    {
        $a = new StyledComponent();
        $b = new StyledComponent();
        $other = new PlainComponent();

        self::assertSame($a->styleScope(), $b->styleScope());
        self::assertNotSame($a->styleScope(), $other->styleScope());
        self::assertMatchesRegularExpression('/^fx-[0-9a-f]{10}$/', $a->styleScope());
    }

    public function test_rendered_component_carries_scope_and_scoped_style_tag(): void
    {
        $application = $this->application();
        $component = new StyledComponent();

        $html = $this->renderComponent($application, $component);

        self::assertStringContainsString('data-fx="'.$component->styleScope().'"', $html);
        self::assertStringContainsString('<style data-firefiber-style="'.$component->styleScope().'">', $html);
        self::assertStringContainsString('[data-fx="'.$component->styleScope().'"] .counter', $html);
    }

    public function test_duplicate_component_instances_emit_the_style_once(): void
    {
        $application = $this->application();
        $renderer = $application->container()->make(PageRenderer::class);
        $page = new StyledPage($renderer);
        $page->items = [new StyledComponent(), new StyledComponent()];

        $html = $renderer->render($this->getRequest('/styled'), $page)->body();

        self::assertSame(2, substr_count($html, 'data-firefiber-component-name="'.StyledComponent::class.'"'));
        // Same class ⇒ one shared scope ⇒ one style tag for both instances.
        $scope = (new StyledComponent())->styleScope();
        self::assertSame(1, substr_count($html, 'data-firefiber-style="'.$scope.'"'));
    }

    public function test_external_stylesheet_is_loaded_and_scoped(): void
    {
        file_put_contents(
            $this->root.'/resources/css/firefiber/card.css',
            '.card { border: 0; } @media (min-width: 10em) { .card { border-width: 1px; } }',
        );

        $application = $this->application();
        $component = new FileStyledComponent();
        $html = $this->renderComponent($application, $component);

        self::assertStringContainsString('[data-fx="'.$component->styleScope().'"] .card', $html);
        self::assertStringContainsString('@media (min-width: 10em)', $html);
    }

    public function test_stylesheet_paths_cannot_escape_resources(): void
    {
        $application = $this->application();
        $component = new TraversalStyledComponent();

        $this->expectException(InvalidStateException::class);
        $this->renderComponent($application, $component);
    }

    public function test_missing_stylesheets_fail_loudly(): void
    {
        $application = $this->application();
        $component = new MissingStyledComponent();

        $this->expectException(InvalidStateException::class);
        $this->renderComponent($application, $component);
    }

    public function test_global_styles_are_explicit_and_unscoped(): void
    {
        $application = $this->application();
        $html = $this->renderComponent($application, new GlobalStyledComponent());

        self::assertStringContainsString('<style data-firefiber-style="global:', $html);
        self::assertStringContainsString('.theme-banner { position: fixed; }', $html);
        self::assertStringNotContainsString('[data-fx="', explode('.theme-banner', $html)[1] ?? '');
    }

    public function test_component_without_styles_emits_no_style_tags(): void
    {
        $application = $this->application();
        $html = $this->renderComponent($application, new PlainComponent());

        self::assertStringNotContainsString('data-firefiber-style', $html);
        // The scope attribute is always present — it is the styling hook
        // and costs nothing when unused.
        self::assertStringContainsString('data-fx="', $html);
    }

    public function test_page_root_carries_the_scope_and_page_styles(): void
    {
        $application = $this->application();
        $renderer = $application->container()->make(PageRenderer::class);
        $page = new StyledPage($renderer);

        $response = $renderer->render($this->getRequest('/styled'), $page);
        $html = $response->body();

        self::assertStringContainsString('data-fx="'.$page->styleScope().'"', $html);
        self::assertStringContainsString('[data-fx="'.$page->styleScope().'"] .page-title', $html);
        // Page styles land in the document head of the fallback document.
        self::assertStringContainsString('</head>', $html);
    }

    public function test_fragment_rerenders_re_emit_the_style_tag_for_swap_integrity(): void
    {
        $application = $this->application();
        $renderer = $application->container()->make(PageRenderer::class);
        $component = new StyledComponent();

        $renderer->renderComponent($component);
        $fragment = $renderer->renderFragment($component);

        self::assertStringContainsString('data-firefiber-style="'.$component->styleScope().'"', $fragment);
    }

    private function getRequest(string $uri): Request
    {
        return Request::fromGlobals([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => $uri,
            'REQUEST_SCHEME' => 'http',
            'HTTP_HOST' => 'styles.test',
        ], [], [], [], '');
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

final class StyledComponent extends Component
{
    public function __construct()
    {
        parent::__construct([]);
    }

    protected function styles(): string
    {
        return '.counter { display: flex; } .counter:hover { opacity: .8; }';
    }

    public function render(): string
    {
        return '<div class="counter">styled</div>';
    }
}

final class FileStyledComponent extends Component
{
    public function __construct()
    {
        parent::__construct([]);
    }

    protected function styleFile(): ?string
    {
        return 'css/firefiber/card.css';
    }

    public function render(): string
    {
        return '<div class="card">file</div>';
    }
}

final class TraversalStyledComponent extends Component
{
    public function __construct()
    {
        parent::__construct([]);
    }

    protected function styleFile(): ?string
    {
        return '../../etc/passwd';
    }

    public function render(): string
    {
        return '<div></div>';
    }
}

final class MissingStyledComponent extends Component
{
    public function __construct()
    {
        parent::__construct([]);
    }

    protected function styleFile(): ?string
    {
        return 'css/firefiber/nope.css';
    }

    public function render(): string
    {
        return '<div></div>';
    }
}

final class GlobalStyledComponent extends Component
{
    public function __construct()
    {
        parent::__construct([]);
    }

    protected function globalStyles(): string
    {
        return '.theme-banner { position: fixed; }';
    }

    public function render(): string
    {
        return '<div>global</div>';
    }
}

final class PlainComponent extends Component
{
    public function __construct()
    {
        parent::__construct([]);
    }

    public function render(): string
    {
        return '<div>plain</div>';
    }
}

final class StyledPage extends Page
{
    /** @var list<Component> */
    public array $items = [];

    public function path(): string
    {
        return '/styled';
    }

    public function title(): string
    {
        return 'Styled';
    }

    protected function styles(): string
    {
        return '.page-title { letter-spacing: .05em; }';
    }

    public function components(): array
    {
        return $this->items;
    }
}
