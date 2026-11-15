<?php

declare(strict_types=1);

namespace Sahil\Contracts\View;

use Sahil\View\RenderedView;

/**
 * Optional extension of {@see ViewContract} for callers that need more than
 * markup: the rendered view keeps the original template name, the data and
 * the rendering context, which frontend adapters use to resolve an entry
 * component and pass PHP data to JavaScript.
 *
 * The native {@see \Sahil\View\ViewEngine} implements it. Keeping this
 * contract separate from `ViewContract` means existing implementations and
 * controllers that only need `render(): string` are unaffected.
 */
interface RenderingViewContract extends ViewContract
{
    /**
     * Render a template and keep its rendering metadata.
     *
     * @param array<string, mixed> $data values the template may read
     */
    public function renderView(string $view, array $data = []): RenderedView;
}
