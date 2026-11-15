<?php

declare(strict_types=1);

namespace Sahil\Firefiber\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sahil\Firefiber\ComponentRenderer;
use Sahil\Firefiber\FireAdapter;
use Sahil\Firefiber\FirefiberSecrets;
use Sahil\Firefiber\PageRenderer;
use Sahil\Firefiber\SnapshotIntegrity;
use Sahil\Firefiber\Storage\FileCacheStateStorage;
use Sahil\Http\Request;
use Sahil\Rendering\RenderManager;
use Sahil\View\TemplateCompiler;
use Sahil\View\TemplateResolver;
use Sahil\View\ViewEngine;

/**
 * The `fire`/`fiber` render environment resolves page names to Sahil
 * templates under `resources/views/firefiber/pages`, falling back to the
 * component renderer for component class names.
 */
final class FireAdapterTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/firefiber-adapter-'.bin2hex(random_bytes(6));

        self::assertTrue(mkdir($this->root.'/app/Firefiber/Components', 0o700, true));
        self::assertTrue(mkdir($this->root.'/app/Firefiber/Pages', 0o700, true));
        self::assertTrue(mkdir($this->root.'/resources/views/firefiber/pages/admin', 0o700, true));
        self::assertTrue(mkdir($this->root.'/storage/framework/views', 0o700, true));
        self::assertTrue(mkdir($this->root.'/state', 0o700, true));

        file_put_contents(
            $this->root.'/composer.json',
            json_encode([
                'name' => 'acme/application',
                'autoload' => ['psr-4' => ['Acme\\' => 'app/']],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );

        file_put_contents(
            $this->root.'/app/Firefiber/Components/AdapterBadge.php',
            <<<'PHP'
<?php

declare(strict_types=1);

namespace Acme\Firefiber\Components;

use Sahil\Firefiber\Component;

final class AdapterBadge extends Component
{
    public function render(): string
    {
        return '<span class="badge">'.$this->property('label', 'ok').'</span>';
    }

    public function handleAction(string $action, array $payload): array
    {
        throw new \Sahil\Firefiber\Exceptions\UnknownActionException($action, $this->id());
    }
}
PHP,
        );

        $root = $this->root;
        spl_autoload_register(static function (string $class) use ($root): void {
            if (!str_starts_with($class, 'Acme\\')) {
                return;
            }

            $file = $root.'/app/'.str_replace('\\', '/', substr($class, 5)).'.php';

            if (is_file($file)) {
                require_once $file;
            }
        });
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function test_page_name_resolves_to_firefiber_pages_template(): void
    {
        $this->view('dashboard', '<h1>{{ title }}</h1>');

        $response = $this->adapter()->render('dashboard', ['title' => 'Karibu'], 200);

        self::assertSame(200, $response->status());
        self::assertStringContainsString('<h1>Karibu</h1>', $response->body());
        self::assertStringContainsString('text/html', (string) $response->header('Content-Type'));
    }

    public function test_nested_page_names_map_to_subdirectories(): void
    {
        $this->view('admin/users', '<p>admin users</p>');

        $response = $this->adapter()->render('admin/users', [], 200);

        self::assertStringContainsString('<p>admin users</p>', $response->body());
    }

    public function test_namespaced_view_names_pass_through_unprefixed(): void
    {
        $this->view('dashboard', '<em>direct</em>');

        $adapter = $this->adapter();

        self::assertStringContainsString('<em>direct</em>', $adapter->render('firefiber.pages.dashboard', [], 200)->body());
        self::assertStringContainsString('<em>direct</em>', $adapter->render('firefiber.pages.dashboard', [], 200)->body());
    }

    public function test_component_names_still_render_through_the_component_path(): void
    {
        $response = $this->adapter()->render('AdapterBadge', [], 200);

        self::assertStringContainsString('<span class="badge">', $response->body());
        self::assertStringContainsString('data-firefiber-component', $response->body());
    }

    public function test_page_template_wins_over_a_component_of_the_same_name(): void
    {
        $this->view('adapter_badge', '<i>template</i>');

        $response = $this->adapter()->render('adapter_badge', [], 200);

        self::assertStringContainsString('<i>template</i>', $response->body());
    }

    public function test_unknown_page_reports_the_expected_template_location(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Unknown Firefiber page \[missing\]/');
        $this->expectExceptionMessageMatches('/firefiber\/pages\/missing\.sahil\.php/');

        $this->adapter()->render('missing', [], 200);
    }

    public function test_fiber_environment_aliases_to_the_fire_adapter(): void
    {
        $this->view('dashboard', '<b>aliased</b>');

        $manager = new RenderManager([$this->adapter()]);
        $manager->alias('fiber', 'fire');

        self::assertContains('fiber', $manager->environments());
        self::assertStringContainsString('<b>aliased</b>', $manager->render('fiber', 'dashboard', [], 200)->body());
    }

    public function test_page_renderer_uses_the_conventional_template_without_view_override(): void
    {
        file_put_contents(
            $this->root.'/app/Firefiber/Pages/AdapterReportPage.php',
            <<<'PHP'
<?php

declare(strict_types=1);

namespace Acme\Firefiber\Pages;

use Acme\Firefiber\Components\AdapterBadge;
use Sahil\Firefiber\Page;

final class AdapterReportPage extends Page
{
    public function path(): string
    {
        return '/firefiber/report';
    }

    public function title(): string
    {
        return 'Report';
    }

    public function components(): array
    {
        return [new AdapterBadge()];
    }
}
PHP,
        );
        $this->view('adapter_report', '<article>{{ page_title }}</article>@foreach (components as component){!! component !!}@endforeach');

        [, , $application] = $this->wired();

        /** @var PageRenderer $renderer */
        $renderer = $application->container()->make(PageRenderer::class);

        $response = $renderer->render(
            Request::fromGlobals([
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/firefiber/report',
                'REQUEST_SCHEME' => 'http',
                'HTTP_HOST' => 'firefiber.test',
            ], [], [], [], ''),
            new \Acme\Firefiber\Pages\AdapterReportPage($renderer),
        );

        self::assertStringContainsString('<article>Report</article>', $response->body());
        self::assertStringContainsString('<span class="badge">', $response->body());
    }

    public function test_page_renderer_keeps_the_document_fallback_without_a_template(): void
    {
        file_put_contents(
            $this->root.'/app/Firefiber/Pages/OrphanPage.php',
            <<<'PHP'
<?php

declare(strict_types=1);

namespace Acme\Firefiber\Pages;

use Acme\Firefiber\Components\AdapterBadge;
use Sahil\Firefiber\Page;

final class OrphanPage extends Page
{
    public function path(): string
    {
        return '/firefiber/orphan';
    }

    public function title(): string
    {
        return 'Orphan';
    }

    public function components(): array
    {
        return [new AdapterBadge()];
    }
}
PHP,
        );

        [, , $application] = $this->wired();

        /** @var PageRenderer $renderer */
        $renderer = $application->container()->make(PageRenderer::class);

        $response = $renderer->render(
            Request::fromGlobals([
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/firefiber/orphan',
                'REQUEST_SCHEME' => 'http',
                'HTTP_HOST' => 'firefiber.test',
            ], [], [], [], ''),
            new \Acme\Firefiber\Pages\OrphanPage($renderer),
        );

        self::assertStringContainsString('<!doctype html>', $response->body());
        self::assertStringContainsString('Orphan', $response->body());
    }

    private function adapter(): FireAdapter
    {
        [$components, $views] = $this->wired();

        return new FireAdapter($components, $views);
    }

    /**
     * One application + container per test: the component renderer resolves
     * PageRenderer lazily, so both must share the same container bindings.
     *
     * @return array{0: ComponentRenderer, 1: ViewEngine, 2: FireAdapterTestApplication}
     */
    private function wired(): array
    {
        $application = new FireAdapterTestApplication($this->root);
        $components = new ComponentRenderer($application);
        $views = new ViewEngine(
            new TemplateResolver([$this->root.'/resources/views'], '.sahil.php'),
            new TemplateCompiler($this->root.'/storage/framework/views'),
            null,
            $components,
        );
        $renderer = new PageRenderer(
            $application,
            new FileCacheStateStorage($this->root.'/state'),
            new SnapshotIntegrity(FirefiberSecrets::masterKey($application)),
            $views,
        );
        $application->container()->instance(\Sahil\Contracts\Foundation\ApplicationContract::class, $application);
        $application->container()->instance(PageRenderer::class, $renderer);

        return [$components, $views, $application];
    }

    private function view(string $name, string $markup): void
    {
        file_put_contents($this->root.'/resources/views/firefiber/pages/'.$name.'.sahil.php', $markup);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($directory);
    }
}

final class FireAdapterTestApplication implements \Sahil\Contracts\Foundation\ApplicationContract
{
    private ?\Sahil\Container\Container $container = null;

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
        return $this->container ??= new \Sahil\Container\Container();
    }

    public function environment(): \Sahil\Support\Environment
    {
        return new \Sahil\Support\Environment([]);
    }

    public function config(): \Sahil\Config\Repository
    {
        return new \Sahil\Config\Repository(['app' => ['name' => 'Test', 'key' => 'fireadapter-test-key']]);
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
