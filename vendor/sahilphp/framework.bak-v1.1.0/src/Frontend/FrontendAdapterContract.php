<?php

declare(strict_types=1);

namespace Sahil\Frontend;

use Sahil\View\RenderedView;

/**
 * The framework-independent contract every frontend integration implements.
 *
 * An adapter knows ONE JavaScript framework. It decides which component a
 * template maps to, which PHP data reaches the browser, and which assets the
 * page loads in development and production. It never writes the HTML
 * document itself: mounting, payload serialization and asset tags are
 * shared work in {@see FrontendRenderer}, so Vue, React and Svelte cannot
 * drift apart in safety or structure.
 *
 * A starter-kit registers exactly one adapter:
 *
 *     $frontends->register(new VueAdapter($assets));
 */
interface FrontendAdapterContract
{
    /**
     * Target name this adapter handles (`vue`, `react`, `svelte`, …).
     *
     * Lower-case letters, digits and dashes; it must match the name used by
     * the response factory (`Response::vue()` targets `vue`).
     */
    public function name(): string;

    /**
     * Resolve the frontend entry component for a rendered template.
     *
     * For example `pages.home` may map to `Pages/Home.vue`. The renderer
     * validates the returned name so an untrusted template name can never
     * inject markup or a path traversal into the mount element.
     */
    public function component(RenderedView $view): string;

    /**
     * The data handed to the component, as a JSON-serializable structure.
     *
     * This is server-to-browser data, not template markup: the renderer
     * serializes it with the same escaped JSON channel the template engine
     * uses, so a payload can never close a `<script>` element.
     *
     * @return array<string, mixed>
     */
    public function payload(RenderedView $view): array;

    /**
     * Extra attributes for the mount element, e.g. `data-props`.
     *
     * Attribute names and values are validated and escaped by the renderer;
     * event-handler attributes (`on*`) are refused.
     *
     * @return array<string, bool|int|float|string>
     */
    public function mountAttributes(RenderedView $view): array;

    /**
     * Scripts and styles for the current environment.
     *
     * Development and production differ (Vite dev server vs built files),
     * so the renderer passes the resolved mode explicitly instead of the
     * adapter guessing from configuration.
     */
    public function assets(bool $development): FrontendAssets;
}
