<?php

declare(strict_types=1);

namespace Sahil\Firefiber\Assets;

/**
 * Produces the script tag that loads Firefiber's browser runtime from the
 * package-managed asset alias.
 *
 * The tag uses a content-addressed URL (`?v=<hash>`) and Subresource
 * Integrity. Development mode points at the readable source file so a
 * developer can debug it without a build step; production mode points at the
 * optimized `runtime.min.js` artifact.
 *
 * This is intentionally a small render helper rather than a response
 * rewriter. The SahilPHP framework has no automatic asset injection hook, so
 * an application layout calls {@see self::scriptTag()} or uses the existing
 * `@asset_js('@fiberscripts')` template directive. Firefiber.boot() remains
 * entirely explicit.
 */
final class RuntimeAssets
{
    public function __construct(
        private readonly AssetRepository $assets,
        private readonly bool $development = false,
    ) {
    }

    public function isDevelopment(): bool
    {
        return $this->development;
    }

    public function name(): string
    {
        return $this->development ? 'runtime.js' : 'runtime.min.js';
    }

    public function url(): string
    {
        return $this->assets->runtimeUrl($this->development);
    }

    public function integrity(): string
    {
        return $this->assets->runtimeAsset($this->development)->integrity();
    }

    /**
     * Render a deferred runtime script tag.
     *
     * Only safe attribute names are accepted. `src`, `integrity`, `defer`
     * and `on*` handlers cannot be overridden by callers; pass `nonce`,
     * `crossorigin`, `referrerpolicy`, `id`, `class`, `data-*` and similar
     * metadata instead.
     *
     * @param array<array-key, bool|int|float|string|\Stringable|null> $attributes
     */
    public function scriptTag(array $attributes = []): string
    {
        return AssetTagRenderer::script(
            $this->assets->runtimeAsset($this->development),
            $attributes,
        );
    }
}
