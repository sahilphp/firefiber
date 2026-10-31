<?php

declare(strict_types=1);

namespace Sahil\Assets;

use Sahil\Contracts\Assets\AssetManagerContract;
use Sahil\Ui\Profile;
use Sahil\Ui\ProfileDetector;
use Sahil\View\Escaper;
use Stringable;

/**
 * Resolves UI entry points to development-server or production-build URLs
 * and emits the corresponding HTML tags.
 *
 * The manager has one entry map per asset type. A profile may only use
 * entries it actually installed, so a Tailwind layout cannot silently pull
 * in Bootstrap (and vice versa). `main` expands to exactly the active
 * profile's entries; `both` is the only profile that expands to two CSS
 * frameworks, and only because the developer selected it explicitly.
 */
final class AssetManager implements AssetManagerContract
{
    /**
     * @var array<string, array{profiles: list<Profile>, source: string, output: string}>
     */
    private const CSS_ENTRIES = [
        'tailwind' => [
            'profiles' => [Profile::Tailwind, Profile::Both],
            'source' => 'resources/css/tailwind.css',
            'output' => 'assets/tailwind.css',
        ],
        'bootstrap' => [
            'profiles' => [Profile::Bootstrap, Profile::Both],
            'source' => 'resources/css/bootstrap.css',
            'output' => 'assets/bootstrap.css',
        ],
        'app' => [
            'profiles' => [Profile::None],
            'source' => 'resources/css/app.css',
            'output' => 'assets/app.css',
        ],
    ];

    /**
     * @var array<string, array{profiles: list<Profile>, source: string, output: string}>
     */
    private const JS_ENTRIES = [
        'bootstrap' => [
            'profiles' => [Profile::Bootstrap, Profile::Both],
            'source' => 'resources/js/bootstrap.js',
            'output' => 'assets/bootstrap.js',
        ],
    ];

    private readonly string $basePath;

    private readonly string $profileSetting;

    private readonly bool $development;

    private readonly string $devServer;

    private readonly string $baseUrl;

    private readonly string $buildPath;

    private readonly string $buildPublicPath;

    private ?Profile $detectedProfile = null;

    private bool $profileDetected = false;

    public function __construct(
        string $basePath,
        string $profile = 'auto',
        string $mode = 'auto',
        string $environment = 'development',
        string $devServer = 'http://127.0.0.1:5173',
        string $baseUrl = '',
        string $buildPath = 'public/build',
        string $buildPublicPath = 'build',
        private readonly bool $verify = true,
        private readonly ProfileDetector $detector = new ProfileDetector(),
    ) {
        $basePath = rtrim($basePath, '/');

        if ($basePath === '' || !is_dir($basePath)) {
            throw new AssetException(sprintf('Asset base path [%s] is not a directory.', $basePath));
        }

        $this->basePath = $basePath;

        $profile = trim($profile);

        if ($profile === '') {
            $profile = 'auto';
        }

        if (strcasecmp($profile, 'auto') !== 0 && Profile::resolve($profile) === null) {
            throw new AssetException(sprintf(
                'Unknown asset profile [%s]. Use tailwind, bootstrap, both, none, or auto.',
                $profile,
            ));
        }

        $this->profileSetting = $profile;
        $this->development = self::resolveMode($mode, $environment);
        $this->devServer = self::normalizeServer($devServer);
        $this->baseUrl = self::normalizeBaseUrl($baseUrl);
        $this->buildPath = self::normalizeRelativePath($buildPath, 'assets.build_path');
        $this->buildPublicPath = self::normalizeRelativePath($buildPublicPath, 'assets.build_public_path');
    }

    public function profile(): ?string
    {
        return $this->detectProfile()?->value;
    }

    public function isDevelopment(): bool
    {
        return $this->development;
    }

    public function publicUrl(string $path): string
    {
        return $this->baseUrl.'/'.self::encodePath($path);
    }

    public function css(string $entry = 'main', array $attributes = []): string
    {
        $tags = [];

        foreach ($this->resolveEntries($entry, 'css') as $asset) {
            $tags[] = '<link'.$this->renderAttributes(
                ['rel' => 'stylesheet', 'href' => $this->assetUrl($asset)],
                $attributes,
                ['rel', 'href'],
            ).'>';
        }

        return implode("\n", $tags);
    }

    public function js(string $entry = 'main', array $attributes = []): string
    {
        $tags = [];

        foreach ($this->resolveEntries($entry, 'js') as $asset) {
            $tags[] = '<script'.$this->renderAttributes(
                ['type' => 'module', 'src' => $this->assetUrl($asset)],
                $attributes,
                ['src', 'type'],
            ).'></script>';
        }

        return implode("\n", $tags);
    }

    public function developmentClient(): string
    {
        if (!$this->development) {
            return '';
        }

        return '<script'.$this->renderAttributes(
            ['type' => 'module', 'src' => $this->devServerBase().'/@vite/client'],
            [],
            ['src', 'type'],
        ).'></script>';
    }

    private function detectProfile(): ?Profile
    {
        if ($this->profileDetected) {
            return $this->detectedProfile;
        }

        $this->profileDetected = true;

        if (strcasecmp($this->profileSetting, 'auto') !== 0) {
            $this->detectedProfile = Profile::resolve($this->profileSetting);

            return $this->detectedProfile;
        }

        [$profile] = $this->detector->detect($this->basePath);
        $this->detectedProfile = $profile;

        return $this->detectedProfile;
    }

    private function requireProfile(): Profile
    {
        $profile = $this->detectProfile();

        if ($profile === null) {
            throw new AssetException(
                'No UI profile is configured or could be inferred. '
                .'Run `bin/sahil ui:install tailwind|bootstrap|both|none`, '
                .'or set ASSET_PROFILE explicitly.',
            );
        }

        return $profile;
    }

    /**
     * @return list<array{profiles: list<Profile>, source: string, output: string}>
     */
    private function resolveEntries(string $entry, string $type): array
    {
        $profile = $this->requireProfile();
        $map = $type === 'css' ? self::CSS_ENTRIES : self::JS_ENTRIES;
        $entry = trim($entry);

        if ($entry === '') {
            throw new AssetException(sprintf('The %s entry point name cannot be empty.', $type));
        }

        $names = $entry === 'main'
            ? ($type === 'css' ? $profile->cssEntries() : $profile->jsEntries())
            : [$entry];

        $resolved = [];

        foreach ($names as $name) {
            if (!isset($map[$name])) {
                throw new AssetException(sprintf(
                    'Unknown %s entry point [%s]. Available: %s.',
                    $type,
                    $name,
                    implode(', ', array_keys($map)),
                ));
            }

            $definition = $map[$name];

            if (!in_array($profile, $definition['profiles'], true)) {
                throw new AssetException(sprintf(
                    'The active UI profile [%s] does not include the [%s] %s entry point. '
                    .'Install the [both] profile if both frameworks are intended; '
                    .'SahilPHP never loads a framework outside the active profile.',
                    $profile->value,
                    $name,
                    $type,
                ));
            }

            $resolved[] = $definition;
        }

        return $resolved;
    }

    /**
     * @param array{profiles: list<Profile>, source: string, output: string} $asset
     */
    private function assetUrl(array $asset): string
    {
        if ($this->development) {
            return $this->devServerBase().'/'.self::encodePath($asset['source']);
        }

        $output = ltrim($asset['output'], '/');
        $file = $this->basePath.'/'.$this->buildPath.'/'.$output;

        if ($this->verify && !is_file($file)) {
            throw new AssetException(sprintf(
                'Missing production asset [%s/%s]. Run `bin/sahil ui:build` before serving production requests.',
                $this->buildPath,
                $output,
            ));
        }

        return $this->publicUrl($this->buildPublicPath.'/'.$output);
    }

    /**
     * @param array<string, bool|int|float|string|Stringable|null> $values default attributes
     * @param array<string, bool|int|float|string|Stringable|null> $extra caller attributes
     * @param list<string> $reserved attribute names the caller may not override
     */
    private function renderAttributes(array $values, array $extra, array $reserved): string
    {
        $attributes = $values;

        foreach ($extra as $name => $value) {
            if (preg_match('/^[A-Za-z_:][A-Za-z0-9_.:-]*$/', $name) !== 1) {
                throw new AssetException(sprintf('Invalid HTML attribute name [%s].', $name));
            }

            $lower = strtolower($name);

            if (in_array($lower, $reserved, true)) {
                throw new AssetException(sprintf('Attribute [%s] is managed by the asset manager.', $name));
            }

            if (str_starts_with($lower, 'on')) {
                throw new AssetException(sprintf(
                    'Event-handler attribute [%s] is not allowed; attach behaviour from JavaScript instead.',
                    $name,
                ));
            }

            $attributes[$name] = $value;
        }

        $html = '';

        foreach ($attributes as $name => $value) {
            if ($value === null || $value === false) {
                continue;
            }

            if ($value === true) {
                $html .= ' '.$name;

                continue;
            }

            $html .= ' '.$name.'="'.Escaper::html(self::stringifyAttribute($name, $value)).'"';
        }

        return $html;
    }

    private static function stringifyAttribute(string $name, mixed $value): string
    {
        if (!is_scalar($value) && !$value instanceof Stringable) {
            throw new AssetException(sprintf(
                'Attribute [%s] must be a scalar or stringable value, got [%s].',
                $name,
                get_debug_type($value),
            ));
        }

        return (string) $value;
    }

    /**
     * Development asset base URL.
     *
     * `ui:dev` writes `public/hot` with the Vite server it actually started, so
     * when 5173 is busy and Vite moves to the next free port the templates
     * follow it without a PHP restart or a configuration edit. A missing or
     * malformed file falls back to the configured `assets.dev_server`.
     */
    private function devServerBase(): string
    {
        $hot = $this->basePath.'/public/hot';

        if (!is_file($hot)) {
            return $this->devServer;
        }

        $contents = @file_get_contents($hot);

        if ($contents === false || trim($contents) === '') {
            return $this->devServer;
        }

        try {
            return self::normalizeServer(trim($contents));
        } catch (AssetException) {
            return $this->devServer;
        }
    }

    private static function resolveMode(string $mode, string $environment): bool
    {
        $mode = strtolower(trim($mode));

        if ($mode === '' || $mode === 'auto') {
            return strcasecmp(trim($environment), 'production') !== 0;
        }

        return match ($mode) {
            'development', 'dev', 'local', 'testing' => true,
            'production', 'prod', 'build' => false,
            default => throw new AssetException(sprintf(
                'Unknown asset mode [%s]. Use auto, development, or production.',
                $mode,
            )),
        };
    }

    private static function normalizeServer(string $server): string
    {
        $server = rtrim(trim($server), '/');
        $parts = parse_url($server);

        if ($server === ''
            || $parts === false
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new AssetException(sprintf(
                'Invalid asset development server [%s]. Use an http(s) URL such as http://127.0.0.1:5173.',
                $server,
            ));
        }

        return $server;
    }

    private static function normalizeBaseUrl(string $baseUrl): string
    {
        $baseUrl = trim($baseUrl);

        if ($baseUrl === '') {
            return '';
        }

        if (str_contains($baseUrl, "\0")
            || str_contains($baseUrl, '\\')
            || str_contains($baseUrl, '?')
            || str_contains($baseUrl, '#')
        ) {
            throw new AssetException(sprintf('Invalid asset base URL [%s].', $baseUrl));
        }

        if (str_starts_with($baseUrl, '/')) {
            if (str_starts_with($baseUrl, '//')) {
                throw new AssetException('Asset base URLs must not be protocol-relative.');
            }

            return rtrim($baseUrl, '/');
        }

        $parts = parse_url($baseUrl);

        if ($parts === false
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new AssetException(sprintf(
                'Invalid asset base URL [%s]. Use /subdirectory or an absolute http(s) URL.',
                $baseUrl,
            ));
        }

        return rtrim($baseUrl, '/');
    }

    private static function normalizeRelativePath(string $path, string $name): string
    {
        $path = trim(trim($path), '/');

        if ($path === ''
            || str_contains($path, "\0")
            || str_contains($path, '\\')
            || preg_match('#^[A-Za-z][A-Za-z0-9+.-]*:#', $path) === 1
        ) {
            throw new AssetException(sprintf('[%s] must be a project-relative path.', $name));
        }

        $segments = explode('/', $path);

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new AssetException(sprintf('[%s] contains an invalid path segment.', $name));
            }
        }

        return implode('/', $segments);
    }

    /**
     * Validate and percent-encode a public URL path. Absolute URLs, query
     * strings and traversal are rejected: callers name a file under `public/`.
     */
    private static function encodePath(string $path): string
    {
        $path = trim($path);

        if ($path === ''
            || str_contains($path, "\0")
            || str_contains($path, '\\')
            || str_contains($path, '?')
            || str_contains($path, '#')
            || preg_match('#^[A-Za-z][A-Za-z0-9+.-]*:#', $path) === 1
            || str_starts_with($path, '//')
        ) {
            throw new AssetException(sprintf(
                'Asset path [%s] must be a relative file path such as css/app.css.',
                $path,
            ));
        }

        $segments = explode('/', ltrim($path, '/'));

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new AssetException(sprintf('Asset path [%s] contains an invalid segment.', $path));
            }
        }

        return implode('/', array_map('rawurlencode', $segments));
    }
}
