<?php

declare(strict_types=1);

namespace Sahil\Firefiber\Tests;

use PHPUnit\Framework\TestCase;
use Sahil\Assets\AssetException;
use Sahil\Contracts\Assets\AssetManagerContract;
use Sahil\Firefiber\Assets\AssetRepository;
use Sahil\Firefiber\Assets\FirefiberAssetManager;
use Sahil\View\TemplateCompiler;
use Sahil\View\TemplateResolver;
use Sahil\View\ViewEngine;

final class AssetAliasTest extends TestCase
{
    private AssetRepository $assets;

    protected function setUp(): void
    {
        $this->assets = new AssetRepository();
    }

    public function test_firefiber_registers_only_the_exact_alias_allowlist(): void
    {
        $this->assertSame(
            ['@fiberscripts', '@fiberstyles'],
            $this->assets->aliasNames(),
        );

        $scripts = $this->assets->resolveAlias('@fiberscripts', 'js');
        $styles = $this->assets->resolveAlias('@fiberstyles', 'css');

        $this->assertNotNull($scripts);
        $this->assertNotNull($styles);
        $this->assertSame('runtime.min.js', $scripts->name());
        $this->assertSame('firefiber.min.css', $styles->name());
        $this->assertSame('application/javascript; charset=utf-8', $scripts->mimeType());
        $this->assertSame('text/css; charset=utf-8', $styles->mimeType());
        $this->assertTrue($scripts->exists());
        $this->assertTrue($styles->exists());
    }

    public function test_aliases_resolve_to_versioned_public_urls_inside_the_package(): void
    {
        $scriptUrl = $this->assets->aliasUrl('@fiberscripts', 'js');
        $styleUrl = $this->assets->aliasUrl('@fiberstyles', 'css');

        $this->assertStringStartsWith('/firefiber/assets/runtime.min.js?v=', $scriptUrl);
        $this->assertStringStartsWith('/firefiber/assets/firefiber.min.css?v=', $styleUrl);

        $packageRoot = realpath(AssetRepository::defaultPackageRoot());
        $this->assertNotFalse($packageRoot);

        foreach ([
            $this->assets->resolveAlias('@fiberscripts', 'js'),
            $this->assets->resolveAlias('@fiberstyles', 'css'),
        ] as $asset) {
            $this->assertNotNull($asset);
            $path = realpath($asset->path());
            $this->assertNotFalse($path);
            $this->assertStringStartsWith($packageRoot.DIRECTORY_SEPARATOR, $path);
        }
    }

    public function test_unknown_mismatched_and_traversal_aliases_are_rejected(): void
    {
        foreach ([
            '@fiberunknown',
            '@FIBERSCRIPTS',
            '@fiberscripts/../../etc/passwd',
            '@fiberstyles%2F..%2Fcomposer.json',
            '@fiberstyles?path=/etc/passwd',
            '@../../etc/passwd',
            '',
        ] as $alias) {
            $this->assertNull($this->assets->resolveAlias($alias, 'js'), $alias);
            $this->assertNull($this->assets->resolveAlias($alias, 'css'), $alias);
        }

        $this->assertNull($this->assets->resolveAlias('@fiberscripts', 'css'));
        $this->assertNull($this->assets->resolveAlias('@fiberstyles', 'js'));

        $this->assertTrue($this->assets->isReservedAlias('@fiberunknown'));
        $this->assertTrue($this->assets->isReservedAlias('@FIBERSCRIPTS'));
        $this->assertFalse($this->assets->isReservedAlias('@appstyles'));
    }

    public function test_alias_manager_emits_script_and_stylesheet_tags_with_integrity(): void
    {
        $manager = new FirefiberAssetManager($this->assets);
        $script = $manager->js('@fiberscripts', ['nonce' => 'abc"<']);
        $stylesheet = $manager->css('@fiberstyles', ['media' => 'screen']);

        $scriptUrl = $this->assets->aliasUrl('@fiberscripts', 'js');
        $styleUrl = $this->assets->aliasUrl('@fiberstyles', 'css');

        $this->assertStringStartsWith('<script ', $script);
        $this->assertStringContainsString('src="'.$scriptUrl.'"', $script);
        $this->assertStringContainsString('integrity="sha384-', $script);
        $this->assertStringContainsString('crossorigin="anonymous"', $script);
        $this->assertStringContainsString('nonce="abc&quot;&lt;"', $script);
        $this->assertStringContainsString(' defer', $script);
        $this->assertStringNotContainsString('type="module"', $script);

        $this->assertStringStartsWith('<link ', $stylesheet);
        $this->assertStringContainsString('rel="stylesheet"', $stylesheet);
        $this->assertStringContainsString('href="'.$styleUrl.'"', $stylesheet);
        $this->assertStringContainsString('integrity="sha384-', $stylesheet);
        $this->assertStringContainsString('media="screen"', $stylesheet);
    }

    public function test_unknown_firefiber_aliases_are_rejected_by_the_manager(): void
    {
        $manager = new FirefiberAssetManager($this->assets);

        try {
            $manager->js('@fibermissing');
            $this->fail('Unknown Firefiber alias should be rejected.');
        } catch (AssetException $exception) {
            $this->assertStringContainsString('@fibermissing', $exception->getMessage());
            $this->assertStringContainsString('@fiberscripts', $exception->getMessage());
            $this->assertStringContainsString('@fiberstyles', $exception->getMessage());
        }

        try {
            $manager->js('@fiberstyles');
            $this->fail('A CSS alias must not be emitted as a script.');
        } catch (AssetException $exception) {
            $this->assertStringContainsString('@asset_css', $exception->getMessage());
        }
    }

    public function test_non_firefiber_entries_are_delegated_to_application_assets(): void
    {
        $application = new class () implements AssetManagerContract {
            /** @var list<string> */
            public array $calls = [];

            public function profile(): ?string
            {
                return 'none';
            }

            public function isDevelopment(): bool
            {
                return true;
            }

            public function publicUrl(string $path): string
            {
                return '/app/'.ltrim($path, '/');
            }

            public function css(string $entry = 'main', array $attributes = []): string
            {
                $this->calls[] = 'css:'.$entry;

                return '<link data-app="css">';
            }

            public function js(string $entry = 'main', array $attributes = []): string
            {
                $this->calls[] = 'js:'.$entry;

                return '<script data-app="js"></script>';
            }

            public function developmentClient(): string
            {
                return '<script data-app="dev"></script>';
            }
        };

        $manager = new FirefiberAssetManager($this->assets, $application);

        $this->assertSame('none', $manager->profile());
        $this->assertTrue($manager->isDevelopment());
        $this->assertSame('/app/css/app.css', $manager->publicUrl('css/app.css'));
        $this->assertSame('<link data-app="css">', $manager->css('main'));
        $this->assertSame('<script data-app="js"></script>', $manager->js('@appscripts'));
        $this->assertSame('<script data-app="dev"></script>', $manager->developmentClient());
        $this->assertSame(['css:main', 'js:@appscripts'], $application->calls);
    }

    public function test_alias_only_manager_reports_non_alias_entries_clearly(): void
    {
        $manager = new FirefiberAssetManager($this->assets);

        try {
            $manager->css('main');
            $this->fail('Non-alias entries need an application asset manager.');
        } catch (AssetException $exception) {
            $this->assertStringContainsString('AssetManagerContract', $exception->getMessage());
        }
    }

    public function test_existing_template_asset_directives_consume_the_aliases(): void
    {
        $root = sys_get_temp_dir().'/firefiber_alias_views_'.bin2hex(random_bytes(8));
        mkdir($root.'/views', 0755, true);
        mkdir($root.'/cache', 0755, true);
        file_put_contents(
            $root.'/views/layout.sahil.php',
            "@asset_js('@fiberscripts')\n@asset_css('@fiberstyles', ['media' => 'screen'])",
        );

        try {
            $engine = new ViewEngine(
                new TemplateResolver([$root.'/views'], '.sahil.php'),
                new TemplateCompiler($root.'/cache'),
                new FirefiberAssetManager($this->assets),
            );

            $output = $engine->render('layout');

            $this->assertStringContainsString('/firefiber/assets/runtime.min.js?v=', $output);
            $this->assertStringContainsString('/firefiber/assets/firefiber.min.css?v=', $output);
            $this->assertStringContainsString('<script ', $output);
            $this->assertStringContainsString('<link ', $output);
            $this->assertStringContainsString('integrity="sha384-', $output);
            $this->assertStringContainsString('media="screen"', $output);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function test_composer_and_release_configuration_include_generated_assets(): void
    {
        $root = AssetRepository::defaultPackageRoot();
        $composer = json_decode((string) file_get_contents($root.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $exclude = $composer['archive']['exclude'] ?? [];

        foreach (['dist', 'css', 'js'] as $directory) {
            $this->assertNotContains('/'.$directory, $exclude);
            $this->assertNotContains($directory, $exclude);
        }

        $attributes = (string) file_get_contents($root.'/.gitattributes');
        $this->assertDoesNotMatchRegularExpression('/^\/(dist|css|js)\s+export-ignore$/m', $attributes);
        $this->assertFileExists($root.'/dist/runtime.min.js');
        $this->assertFileExists($root.'/dist/firefiber.min.css');
        $this->assertFileExists($root.'/css/firefiber.css');
    }

    public function test_composer_archive_contains_generated_assets_only_from_the_package(): void
    {
        $composer = trim((string) @shell_exec('command -v composer 2>/dev/null'));

        if ($composer === '' || !class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('The composer binary and ext-zip are required to inspect the distribution archive.');
        }

        $directory = sys_get_temp_dir().'/firefiber_archive_'.bin2hex(random_bytes(8));
        mkdir($directory, 0755, true);
        $archive = $directory.'/firefiber-package.zip';

        try {
            $command = sprintf(
                '%s archive --format=zip --dir=%s --file=firefiber-package --no-interaction --no-scripts 2>&1',
                escapeshellarg($composer),
                escapeshellarg($directory),
            );
            $output = [];
            $status = 0;
            exec($command, $output, $status);

            $this->assertSame(0, $status, implode("\n", $output));
            $this->assertFileExists($archive);

            $zip = new \ZipArchive();
            $this->assertTrue($zip->open($archive) === true);

            try {
                foreach ([
                    'dist/runtime.min.js',
                    'dist/firefiber.min.css',
                    'dist/manifest.json',
                    'js/firefiber.js',
                    'css/firefiber.css',
                ] as $path) {
                    $this->assertNotFalse($zip->locateName($path), $path.' must ship in the Composer archive.');
                }

                foreach (['tests/', 'vendor/', 'node_modules/'] as $prefix) {
                    for ($index = 0; $index < $zip->numFiles; ++$index) {
                        $name = (string) $zip->getNameIndex($index);
                        $this->assertStringStartsNotWith($prefix, $name, $prefix.' must not be distributed.');
                    }
                }
            } finally {
                $zip->close();
            }
        } finally {
            if (is_file($archive)) {
                unlink($archive);
            }

            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($directory);
    }
}
