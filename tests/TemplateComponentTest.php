<?php

declare(strict_types=1);

namespace Sahil\Firefiber\Tests;

use PHPUnit\Framework\TestCase;
use Sahil\Container\Container;
use Sahil\Contracts\Foundation\ApplicationContract;
use Sahil\Firefiber\Component;
use Sahil\Firefiber\ComponentRenderer;
use Sahil\Firefiber\FirefiberSecrets;
use Sahil\Firefiber\PageRenderer;
use Sahil\Firefiber\SnapshotIntegrity;
use Sahil\Firefiber\Storage\FileCacheStateStorage;
use Sahil\View\TemplateCompiler;
use Sahil\View\TemplateResolver;
use Sahil\View\ViewEngine;

/**
 * A component named in a template is resolved, property-checked and rendered
 * with the same signed markup as a component composed by a page.
 */
final class TemplateComponentTest extends TestCase
{
    private string $root;

    private Container $container;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/firefiber-template-'.bin2hex(random_bytes(6));

        self::assertTrue(mkdir($this->root.'/app/Firefiber/Components', 0o700, true));
        self::assertTrue(mkdir($this->root.'/resources/views', 0o700, true));
        self::assertTrue(mkdir($this->root.'/state', 0o700, true));
        self::assertTrue(mkdir($this->root.'/cache', 0o700, true));
        self::assertTrue(mkdir($this->root.'/sessions', 0o700, true));

        file_put_contents(
            $this->root.'/composer.json',
            json_encode([
                'name' => 'acme/application',
                'autoload' => ['psr-4' => ['Acme\\' => 'app/']],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
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

        file_put_contents($this->root.'/app/Firefiber/Components/TemplateCounter.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Acme\Firefiber\Components;

use Sahil\Firefiber\Component;

final class TemplateCounter extends Component
{
    /** @var array<string, array<string, mixed>> */
    protected array $propertyDefinitions = [
        'start' => ['type' => 'int', 'default' => 0],
        'label' => ['type' => 'string', 'default' => 'Count'],
    ];

    public function __construct()
    {
        parent::__construct(['count' => 0], ['increment']);
    }

    public function render(): string
    {
        return '<span>'.$this->property('label').' '.$this->property('start').'</span>';
    }

    public function handleAction(string $action, array $payload): array
    {
        throw new \Sahil\Firefiber\Exceptions\UnknownActionException($action, $this->id());
    }
}
PHP);

        file_put_contents($this->root.'/app/Firefiber/Components/TemplateRecursive.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Acme\Firefiber\Components;

use Sahil\Firefiber\Component;

final class TemplateRecursive extends Component
{
    public function render(): string
    {
        return '<div>recursive</div>';
    }

    public function handleAction(string $action, array $payload): array
    {
        return [];
    }

    /** @return list<Component> */
    public function children(): array
    {
        return [$this];
    }
}
PHP);

        file_put_contents($this->root.'/app/Firefiber/Components/TemplateNotAComponent.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Acme\Firefiber\Components;

final class TemplateNotAComponent
{
}
PHP);

        $application = new TemplateComponentTestApplication($this->root);
        $this->container = $application->container();

        $this->container->instance(PageRenderer::class, new PageRenderer(
            $application,
            new FileCacheStateStorage($this->root.'/state'),
            new SnapshotIntegrity(FirefiberSecrets::masterKey($application)),
        ));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function test_a_short_component_name_is_resolved_and_rendered_with_hydration_markup(): void
    {
        $html = $this->renderer()->renderComponent('TemplateCounter');

        self::assertStringContainsString('class="firefiber-component"', $html);
        self::assertStringContainsString('data-firefiber-component-name="Acme\\Firefiber\\Components\\TemplateCounter"', $html);
        self::assertStringContainsString('data-firefiber-snapshot="', $html);
        self::assertStringContainsString('<span>Count 0</span>', $html);
    }

    public function test_attributes_are_validated_as_component_properties(): void
    {
        $html = $this->renderer()->renderComponent('TemplateCounter', ['start' => 5, 'label' => 'Total']);

        self::assertStringContainsString('<span>Total 5</span>', $html);
    }

    public function test_an_undeclared_property_is_rejected(): void
    {
        $this->expectException(\Sahil\Firefiber\Exceptions\InvalidStateException::class);

        $this->renderer()->renderComponent('TemplateCounter', ['missing' => 'value']);
    }

    public function test_an_unknown_component_fails_with_a_useful_message(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown Firefiber component [TemplateMissing]');

        $this->renderer()->renderComponent('TemplateMissing');
    }

    public function test_a_non_component_class_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not extend Sahil\\Firefiber\\Component');

        $this->renderer()->renderComponent('TemplateNotAComponent');
    }

    public function test_each_instance_gets_an_independent_identity(): void
    {
        $renderer = $this->renderer();

        $first = $renderer->renderComponent('TemplateCounter');
        $second = $renderer->renderComponent('TemplateCounter');

        preg_match('/data-firefiber-component="([^"]+)"/', $first, $a);
        preg_match('/data-firefiber-component="([^"]+)"/', $second, $b);

        self::assertNotEmpty($a[1]);
        self::assertNotEmpty($b[1]);
        self::assertNotSame($a[1], $b[1]);
    }

    public function test_a_component_that_nests_itself_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Circular Firefiber component nesting');

        $this->renderer()->renderComponent('TemplateRecursive');
    }

    public function test_both_template_syntaxes_render_real_component_markup(): void
    {
        file_put_contents(
            $this->root.'/resources/views/both.sahil.php',
            "<fiber:component name=\"TemplateCounter\" />\n@component('TemplateCounter')",
        );

        $engine = new ViewEngine(
            new TemplateResolver([$this->root.'/resources/views'], '.sahil.php'),
            new TemplateCompiler($this->root.'/cache'),
            null,
            $this->renderer(),
        );

        $html = $engine->render('both');

        self::assertSame(2, substr_count($html, 'class="firefiber-component"'));
        self::assertStringNotContainsString('fiber:component', $html);
        self::assertStringNotContainsString('@component', $html);
        self::assertStringNotContainsString('<!--', $html);
    }

    private function renderer(): ComponentRenderer
    {
        return new ComponentRenderer(new TemplateComponentTestApplication($this->root));
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

final class TemplateComponentTestApplication implements ApplicationContract
{
    private static ?Container $shared = null;

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

    public function container(): Container
    {
        return self::$shared ??= new Container();
    }

    public function environment(): \Sahil\Support\Environment
    {
        return new \Sahil\Support\Environment([]);
    }

    public function config(): \Sahil\Config\Repository
    {
        return new \Sahil\Config\Repository([
            'app' => ['key' => 'base64:'.base64_encode(str_repeat('f', 32))],
        ]);
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
