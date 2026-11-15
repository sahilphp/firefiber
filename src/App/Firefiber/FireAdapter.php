<?php

declare(strict_types=1);

namespace Sahil\Firefiber;

use Sahil\Contracts\Rendering\RenderAdapterContract;
use Sahil\Contracts\View\ComponentRendererContract;
use Sahil\Http\Response;

/**
 * `fire` render environment for the unified rendering API.
 *
 * Delegates to Firefiber's own {@see ComponentRendererContract} — page
 * data becomes component properties — so orchestration stays in core
 * while rendering stays in Firefiber.
 */
final class FireAdapter implements RenderAdapterContract
{
    public function __construct(
        private readonly ComponentRendererContract $components,
    ) {
    }

    public function environment(): string
    {
        return 'fire';
    }

    public function render(string $page, array $data, int $status): Response
    {
        return Response::html($this->components->renderComponent($page, $data), $status);
    }
}
