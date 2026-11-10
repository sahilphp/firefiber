<?php

declare(strict_types=1);

namespace Sahil\Frontend;

/**
 * Scripts and styles a frontend page needs.
 *
 * Adapters resolve these for the current environment (Vite dev server vs a
 * production build) and return final URLs: root-relative (`/build/app.js`),
 * scheme-relative or absolute `http(s)` URLs. The renderer validates them,
 * so an adapter cannot inject markup or a `javascript:` URL into the page.
 */
final class FrontendAssets
{
    /**
     * @param list<string> $scripts module scripts in load order
     * @param list<string> $styles stylesheets in load order
     * @param bool $module whether scripts are ES modules (`type="module"`)
     */
    public function __construct(
        private readonly array $scripts = [],
        private readonly array $styles = [],
        private readonly bool $module = true,
    ) {
    }

    /**
     * @return list<string>
     */
    public function scripts(): array
    {
        return $this->scripts;
    }

    /**
     * @return list<string>
     */
    public function styles(): array
    {
        return $this->styles;
    }

    public function usesModules(): bool
    {
        return $this->module;
    }
}
