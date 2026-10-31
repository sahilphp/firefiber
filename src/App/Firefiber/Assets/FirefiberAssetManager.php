<?php

declare(strict_types=1);

namespace Sahil\Firefiber\Assets;

use Sahil\Assets\AssetException;
use Sahil\Contracts\Assets\AssetManagerContract;

/**
 * Asset manager decorator that adds Firefiber's package-owned aliases to the
 * existing SahilPHP asset pipeline.
 *
 * Only the exact `@fiberscripts` and `@fiberstyles` entries are intercepted.
 * Every other entry, including application-defined aliases and `main`, is
 * delegated unchanged to the application's asset manager, so the package
 * never replaces or reinterprets the application's frontend tooling.
 *
 * `@asset_js('@fiberscripts')` and `@asset_css('@fiberstyles')` therefore
 * use the framework's existing directives without adding template syntax.
 */
final class FirefiberAssetManager implements AssetManagerContract
{
    public function __construct(
        private readonly AssetRepository $assets,
        private readonly ?AssetManagerContract $applicationAssets = null,
    ) {
    }

    public function profile(): ?string
    {
        return $this->applicationAssets?->profile();
    }

    public function isDevelopment(): bool
    {
        return $this->applicationAssets?->isDevelopment() ?? false;
    }

    public function publicUrl(string $path): string
    {
        return $this->requireApplicationManager()->publicUrl($path);
    }

    public function css(string $entry = 'main', array $attributes = []): string
    {
        $alias = $this->aliasTag($entry, 'css', $attributes);

        return $alias ?? $this->requireApplicationManager()->css($entry, $attributes);
    }

    public function js(string $entry = 'main', array $attributes = []): string
    {
        $alias = $this->aliasTag($entry, 'js', $attributes);

        return $alias ?? $this->requireApplicationManager()->js($entry, $attributes);
    }

    public function developmentClient(): string
    {
        return $this->applicationAssets?->developmentClient() ?? '';
    }

    /**
     * @param array<string, bool|int|float|string|\Stringable|null> $attributes
     */
    private function aliasTag(string $entry, string $type, array $attributes): ?string
    {
        $entry = trim($entry);

        if (!$this->assets->isReservedAlias($entry)) {
            return null;
        }

        $asset = $this->assets->resolveAlias($entry, $type);

        if ($asset === null) {
            $otherType = $this->assets->aliasType($entry);

            if ($otherType !== null) {
                throw new AssetException(sprintf(
                    'Firefiber alias [%s] resolves to %s, not %s. Use @asset_%s instead.',
                    $entry,
                    $otherType,
                    $type,
                    $otherType,
                ));
            }

            throw new AssetException(sprintf(
                'Unknown Firefiber asset alias [%s]. Available aliases: %s.',
                $entry,
                implode(', ', $this->assets->aliasNames()),
            ));
        }

        if (!$asset->exists()) {
            throw new AssetException(sprintf(
                'Missing Firefiber production asset for alias [%s]. Run `npm run assets:build` before serving production requests.',
                $entry,
            ));
        }

        return $type === 'css'
            ? AssetTagRenderer::stylesheet($asset, $attributes)
            : AssetTagRenderer::script($asset, $attributes);
    }

    private function requireApplicationManager(): AssetManagerContract
    {
        if ($this->applicationAssets === null) {
            throw new AssetException(
                'The application AssetManagerContract is not registered. '
                .'Register the application asset provider to use non-Firefiber entries; '
                .'Firefiber aliases (@fiberscripts, @fiberstyles) work without it.',
            );
        }

        return $this->applicationAssets;
    }
}
