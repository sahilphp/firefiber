<?php

declare(strict_types=1);

namespace Sahil\Firefiber\Tests;

use PHPUnit\Framework\TestCase;
use Sahil\Console\CommandInput;
use Sahil\Console\CommandOutput;
use Sahil\Console\ConsoleException;
use Sahil\Firefiber\Console\Commands\FirefiberComponentCommand;
use Sahil\Firefiber\Console\Commands\FirefiberPageCommand;

final class GeneratorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/firefiber-generator-'.bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->root.'/app', 0o700, true));

        file_put_contents(
            $this->root.'/composer.json',
            json_encode([
                'name' => 'acme/application',
                'autoload' => ['psr-4' => ['Acme\\' => 'app/']],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );

        self::assertTrue(mkdir($this->root.'/resources/views', 0o700, true));
        self::assertTrue(mkdir($this->root.'/storage', 0o700, true));

        spl_autoload_register(function (string $class): void {
            if (!str_starts_with($class, 'Acme\\')) {
                return;
            }

            $file = $this->root.'/app/'.str_replace('\\', '/', substr($class, 5)).'.php';

            if (is_file($file)) {
                require_once $file;
            }
        });
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function test_component_command_generates_a_resolved_component_class(): void
    {
        [$exit, $out] = $this->executeCommand(new FirefiberComponentCommand(), ['Counter']);

        self::assertSame(0, $exit);
        self::assertStringContainsString('Created app/Firefiber/Components/Counter.php', $out);
        self::assertTrue(class_exists(\Acme\Firefiber\Components\Counter::class));
        $component = new \Acme\Firefiber\Components\Counter();
        self::assertSame(['count' => 1], $component->handleAction('increment', []));
        $component = new \Acme\Firefiber\Components\Counter();
        self::assertStringContainsString('data-firefiber-bind="count"', $component->render());
    }

    public function test_nested_component_names_use_nested_directories_and_namespaces(): void
    {
        [$exit, $out] = $this->executeCommand(new FirefiberComponentCommand(), ['Admin/Stats']);

        self::assertSame(0, $exit);
        self::assertStringContainsString('app/Firefiber/Components/Admin/Stats.php', $out);
        self::assertStringContainsString('namespace Acme\\Firefiber\\Components\\Admin', $out);
        self::assertTrue(class_exists(\Acme\Firefiber\Components\Admin\Stats::class));
    }

    public function test_invalid_names_and_traversal_are_rejected_without_writing(): void
    {
        [$exit, $error] = $this->executeCommandError(new FirefiberComponentCommand(), ['../Counter']);
        self::assertSame(1, $exit);
        self::assertStringContainsString('path traversal', $error);

        [$exit, $error] = $this->executeCommandError(new FirefiberComponentCommand(), ['1Counter']);
        self::assertSame(1, $exit);
        self::assertStringContainsString('not a valid PHP identifier', $error);

        [$exit, $error] = $this->executeCommandError(new FirefiberComponentCommand(), ['class']);
        self::assertSame(1, $exit);
        self::assertStringContainsString('reserved PHP word', $error);

        self::assertFileDoesNotExist($this->root.'/app/Firefiber/Components/Counter.php');
        self::assertDirectoryDoesNotExist($this->root.'/app/Firefiber/Components');
    }

    public function test_existing_files_are_protected_and_force_backs_them_up(): void
    {
        [$exit] = $this->executeCommand(new FirefiberComponentCommand(), ['Counter']);
        self::assertSame(0, $exit);

        $file = $this->root.'/app/Firefiber/Components/Counter.php';
        $original = (string) file_get_contents($file);

        [$exit, $out] = $this->executeCommandError(new FirefiberComponentCommand(), ['Counter']);
        self::assertSame(1, $exit);
        self::assertStringContainsString('already exists', $out);

        [$exit, $out] = $this->executeCommand(new FirefiberComponentCommand(), ['Counter', '--force']);
        self::assertSame(0, $exit);
        self::assertStringContainsString('Updated app/Firefiber/Components/Counter.php', $out);
        self::assertStringContainsString('backup', $out);
        self::assertSame($original, (string) file_get_contents($file));
    }

    public function test_page_command_generates_a_discoverable_page_and_view(): void
    {
        [$exit, $out] = $this->executeCommand(new FirefiberPageCommand(), ['Demo', '--route=/firefiber/demo']);

        self::assertSame(0, $exit);
        self::assertStringContainsString('Created app/Firefiber/Pages/DemoPage.php', $out);
        self::assertStringContainsString('Created resources/views/firefiber/pages/demo.sahil.php', $out);
        self::assertStringContainsString('namespace Acme\\Firefiber\\Pages', $out);
        self::assertFileExists($this->root.'/app/Firefiber/Pages/DemoPage.php');
        self::assertFileExists($this->root.'/resources/views/firefiber/pages/demo.sahil.php');
        self::assertTrue(class_exists(\Acme\Firefiber\Pages\DemoPage::class));
        $page = new \Acme\Firefiber\Pages\DemoPage(new \Sahil\Firefiber\PageRenderer(
            new FakeApplication($this->root),
            new \Sahil\Firefiber\Storage\FileCacheStateStorage($this->root.'/state'),
            new \Sahil\Firefiber\SnapshotIntegrity('test-key'),
        ));
        self::assertSame('/firefiber/demo', $page->path());
        self::assertSame('firefiber.pages.demo', $page->view());
    }

    public function test_example_page_composes_two_independent_components(): void
    {
        [$exit] = $this->executeCommand(new FirefiberPageCommand(), ['ExampleDemo', '--example']);

        self::assertSame(0, $exit);
        self::assertTrue(class_exists(\Acme\Firefiber\Components\Hero::class));
        self::assertTrue(class_exists(\Acme\Firefiber\Components\Stats::class));

        $pageClass = \Acme\Firefiber\Pages\ExampleDemoPage::class;
        $page = new $pageClass(new \Sahil\Firefiber\PageRenderer(
            new FakeApplication($this->root),
            new \Sahil\Firefiber\Storage\FileCacheStateStorage($this->root.'/state'),
            new \Sahil\Firefiber\SnapshotIntegrity('test-key'),
        ));
        $components = $page->components();

        self::assertCount(2, $components);
        self::assertInstanceOf(\Acme\Firefiber\Components\Hero::class, $components[0]);
        self::assertInstanceOf(\Acme\Firefiber\Components\Stats::class, $components[1]);
        self::assertNotSame($components[0]->id(), $components[1]->id());
    }

    /**
     * @param list<string> $arguments
     * @return array{0: int, 1: string}
     */
    private function executeCommand(object $command, array $arguments): array
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        self::assertIsResource($stdout);
        self::assertIsResource($stderr);

        $command->bindBasePath($this->root);
        $input = CommandInput::parse($arguments, $command->optionSpecs());
        $output = new CommandOutput($stdout, $stderr);
        $exit = $command->execute($input, $output);
        rewind($stdout);
        rewind($stderr);

        return [$exit, (string) stream_get_contents($stdout).stream_get_contents($stderr)];
    }

    /**
     * @param list<string> $arguments
     * @return array{0: int, 1: string}
     */
    private function executeCommandError(object $command, array $arguments): array
    {
        try {
            return $this->executeCommand($command, $arguments);
        } catch (ConsoleException $exception) {
            return [1, $exception->getMessage()];
        }
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

final class FakeApplication implements \Sahil\Contracts\Foundation\ApplicationContract
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
        return new \Sahil\Config\Repository(['app' => ['name' => 'Test']]);
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
