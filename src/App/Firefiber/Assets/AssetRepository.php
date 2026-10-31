<?php

declare(strict_types=1);

namespace Sahil\Firefiber\Assets;

use InvalidArgumentException;
use LogicException;

/**
 * The complete, explicit allow-list of browser assets shipped by the
 * installed `sahilphp/firefiber` Composer package.
 *
 * URLs never map to filesystem paths directly. A public name is first looked
 * up in {@see self::CATALOG}; only a catalogued entry can produce an
 * {@see Asset}. The resolved path is then checked against the trusted package
 * root as defence in depth, so a future catalog mistake cannot turn into an
 * arbitrary-file read.
 *
 * The package ships an optional, layout-neutral CSS baseline used by the
 * `@fiberstyles` alias. There is no framework CSS dependency: applications
 * can omit the alias, and the JavaScript runtime works without it.
 */
final class AssetRepository
{
    public const DEFAULT_PUBLIC_PREFIX = '/firefiber/assets';

    public const ROUTE_NAME = 'firefiber.assets';

    public const ALIAS_SCRIPTS = '@fiberscripts';

    public const ALIAS_STYLES = '@fiberstyles';

    /**
     * Firefiber-owned template aliases.
     *
     * The registry is deliberately literal: an alias maps to one catalogued
     * asset and one asset type. The manager intercepts only these exact
     * names, leaving every other entry (including application aliases) with
     * the application's own asset manager.
     *
     * @var array<string, array{name: string, type: 'css'|'js'}>
     */
    private const ALIASES = [
        self::ALIAS_SCRIPTS => ['name' => 'runtime.min.js', 'type' => 'js'],
        self::ALIAS_STYLES => ['name' => 'firefiber.min.css', 'type' => 'css'],
    ];

    /**
     * Package-relative directories that may contain servable assets.
     *
     * The catalog already limits which files exist; this second allowlist
     * keeps a future catalog edit from serving anything else inside the
     * package (for example Composer metadata or PHP sources).
     *
     * @var list<string>
     */
    private const APPROVED_DIRECTORIES = ['dist', 'css', 'js'];

    /**
     * Public name => trusted package-relative path, MIME type and build kind.
     *
     * Keep this list small and literal. Do not derive names from request
     * input and do not add directory scans.
     *
     * @var array<string, array{path: string, mime: string, production: bool}>
     */
    private const CATALOG = [
        'runtime.min.js' => [
            'path' => 'dist/runtime.min.js',
            'mime' => 'application/javascript; charset=utf-8',
            'production' => true,
        ],
        'runtime.js' => [
            'path' => 'js/firefiber.js',
            'mime' => 'application/javascript; charset=utf-8',
            'production' => false,
        ],
        'firefiber.min.css' => [
            'path' => 'dist/firefiber.min.css',
            'mime' => 'text/css; charset=utf-8',
            'production' => true,
        ],
        'firefiber.css' => [
            'path' => 'css/firefiber.css',
            'mime' => 'text/css; charset=utf-8',
            'production' => false,
        ],
    ];

    private readonly string $packageRoot;

    private readonly string $publicPrefix;

    public function __construct(
        string $packageRoot = '',
        string $publicPrefix = self::DEFAULT_PUBLIC_PREFIX,
    ) {
        $packageRoot = $packageRoot === '' ? self::defaultPackageRoot() : $packageRoot;
        $realRoot = realpath($packageRoot);
        $this->packageRoot = $realRoot === false
            ? rtrim(str_replace('\\', '/', $packageRoot), '/')
            : $realRoot;

        $this->publicPrefix = self::normalizePublicPrefix($publicPrefix);
    }

    /**
     * Package root inferred from this source file:
     * `src/App/Firefiber/Assets` -> package root.
     */
    public static function defaultPackageRoot(): string
    {
        return dirname(__DIR__, 4);
    }

    /**
     * Public URL prefix (always starts with `/`, never ends with `/`).
     */
    public function publicPrefix(): string
    {
        return $this->publicPrefix;
    }

    /**
     * Route template matching exactly one asset segment.
     */
    public function routePath(): string
    {
        return $this->publicPrefix.'/{asset}';
    }

    /**
     * @return list<string> allow-listed public names
     */
    public function names(): array
    {
        return array_keys(self::CATALOG);
    }

    /**
     * @return list<string> Firefiber-owned template aliases
     */
    public function aliasNames(): array
    {
        return array_keys(self::ALIASES);
    }

    /**
     * Resolve a Firefiber alias to a catalogued production asset.
     *
     * Returns null for unknown aliases and for a type mismatch, so a CSS
     * alias cannot be emitted as a script tag (or vice versa).
     */
    public function resolveAlias(string $alias, string $type): ?Asset
    {
        $alias = trim($alias);
        $definition = self::ALIASES[$alias] ?? null;

        if ($definition === null || $definition['type'] !== $type) {
            return null;
        }

        return $this->find($definition['name']);
    }

    /**
     * Type of a known Firefiber alias (`css`, `js`) or null when the name is
     * not one of the package's aliases.
     */
    public function aliasType(string $alias): ?string
    {
        return self::ALIASES[trim($alias)]['type'] ?? null;
    }

    /**
     * Whether a name belongs to Firefiber's reserved alias namespace.
     *
     * Unknown `@fiber…` entries are rejected by the asset manager instead of
     * being delegated to the application, which prevents a typo from
     * silently resolving through unrelated tooling. Other `@…` entries stay
     * application-owned.
     */
    public function isReservedAlias(string $name): bool
    {
        return str_starts_with(strtolower(trim($name)), '@fiber');
    }

    /**
     * Versioned public URL for a Firefiber alias.
     *
     * @throws InvalidArgumentException for an unknown alias or type mismatch
     */
    public function aliasUrl(string $alias, string $type): string
    {
        $asset = $this->resolveAlias($alias, $type);

        if ($asset === null) {
            throw new InvalidArgumentException(sprintf('Unknown Firefiber %s alias [%s].', $type, $alias));
        }

        return $asset->publicUrl();
    }

    /**
     * Resolve a public name to an asset, or null for every unknown, malformed
     * or traversal-shaped name.
     */
    public function find(string $name): ?Asset
    {
        if (!self::isSafeName($name) || !array_key_exists($name, self::CATALOG)) {
            return null;
        }

        $definition = self::CATALOG[$name];
        $relative = $definition['path'];

        // The catalog is constant data, but this check keeps the invariant
        // explicit if a future entry is edited incorrectly.
        if (!self::isApprovedPath($relative)) {
            return null;
        }

        $candidate = $this->packageRoot.'/'.$relative;
        $realCandidate = realpath($candidate);

        if (
            $realCandidate !== false
            && (
                !$this->isInsidePackage($realCandidate)
                || !$this->isInsideApprovedDirectory($realCandidate)
            )
        ) {
            return null;
        }

        return new Asset(
            $name,
            $realCandidate === false ? $candidate : $realCandidate,
            $definition['mime'],
            $this->publicPrefix,
            $definition['production'],
        );
    }

    /**
     * Public URL for an allow-listed name.
     *
     * @throws InvalidArgumentException for an unknown name
     */
    public function url(string $name, bool $versioned = true): string
    {
        $asset = $this->find($name);

        if ($asset === null) {
            throw new InvalidArgumentException(sprintf('Unknown Firefiber asset [%s].', $name));
        }

        return $asset->publicUrl($versioned);
    }

    /**
     * Production runtime URL, or the readable source URL in development.
     */
    public function runtimeUrl(bool $development = false): string
    {
        return $this->url($development ? 'runtime.js' : 'runtime.min.js');
    }

    public function runtimeAsset(bool $development = false): Asset
    {
        $asset = $this->find($development ? 'runtime.js' : 'runtime.min.js');

        // The catalog always contains both runtime names.
        if (!$asset instanceof Asset) {
            throw new LogicException('The Firefiber asset catalog is missing a runtime entry.');
        }

        return $asset;
    }

    /**
     * Whether a version query parameter equals the current file hash. Used
     * by the controller to choose an immutable cache policy only for URLs
     * that are genuinely content-addressed.
     */
    public function versionMatches(string $name, string $version): bool
    {
        $asset = $this->find($name);

        if ($asset === null || $version === '' || !$asset->exists()) {
            return false;
        }

        return hash_equals($asset->version(), $version);
    }

    private static function isSafeName(string $name): bool
    {
        return preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/', $name) === 1
            && !str_contains($name, '..');
    }

    /**
     * Whether a package-relative catalog path stays inside one of the
     * approved asset directories and cannot traverse out of it.
     */
    private static function isApprovedPath(string $relative): bool
    {
        if (
            $relative === ''
            || str_starts_with($relative, '/')
            || str_contains($relative, '..')
            || str_contains($relative, '\\')
            || str_contains($relative, "\0")
        ) {
            return false;
        }

        foreach (self::APPROVED_DIRECTORIES as $directory) {
            if (str_starts_with($relative, $directory.'/')) {
                return true;
            }
        }

        return false;
    }

    private function isInsidePackage(string $path): bool
    {
        $root = rtrim(str_replace('\\', '/', $this->packageRoot), '/');

        return $path === $root
            || str_starts_with(str_replace('\\', '/', $path), $root.'/');
    }

    /**
     * Whether a resolved path is inside one of the approved asset
     * directories. This also rejects a symlink inside `dist/` that points at
     * another file in the package, such as `composer.json`.
     */
    private function isInsideApprovedDirectory(string $path): bool
    {
        $root = rtrim(str_replace('\\', '/', $this->packageRoot), '/');
        $path = str_replace('\\', '/', $path);

        foreach (self::APPROVED_DIRECTORIES as $directory) {
            $approved = $root.'/'.$directory;

            if ($path === $approved || str_starts_with($path, $approved.'/')) {
                return true;
            }
        }

        return false;
    }

    private static function normalizePublicPrefix(string $prefix): string
    {
        $prefix = trim($prefix);

        if (
            $prefix === ''
            || $prefix[0] !== '/'
            || str_starts_with($prefix, '//')
            || str_contains($prefix, '..')
            || str_contains($prefix, '?')
            || str_contains($prefix, '#')
            || str_contains($prefix, '\\')
            || preg_match('#^/[A-Za-z0-9._~/\-]*$#', $prefix) !== 1
            || preg_match('/\s/', $prefix) === 1
        ) {
            return self::DEFAULT_PUBLIC_PREFIX;
        }

        $prefix = '/'.trim($prefix, '/');

        return $prefix === '/' ? self::DEFAULT_PUBLIC_PREFIX : $prefix;
    }
}
