<?php

declare(strict_types=1);

namespace Sahil\Firefiber;

use RuntimeException;
use Sahil\Contracts\Rendering\RenderAdapterContract;
use Sahil\Contracts\View\ComponentRendererContract;
use Sahil\Contracts\View\ViewContract;
use Sahil\Http\Response;

/**
 * `fire`/`fiber` render environment for the unified rendering API.
 *
 * A page name resolves to its Sahil template under the application's
 * `resources/views/firefiber/pages` directory — `fire('dashboard')`
 * renders `firefiber.pages.dashboard`, and nested paths
 * (`fire('admin/users')`) map to subdirectories. Names that reach the
 * adapter already namespaced (`firefiber.pages.*`, `firefiber.*`)
 * resolve verbatim.
 *
 * When no page template exists, the name is treated as a component
 * reference (short or fully-qualified class) and delegated to Firefiber's
 * {@see ComponentRendererContract}, preserving the original component
 * rendering behavior. This keeps orchestration in core while rendering
 * stays in Firefiber.
 */
final class FireAdapter implements RenderAdapterContract
{
    public function __construct(
        private readonly ComponentRendererContract $components,
        private readonly ?ViewContract $views = null,
    ) {
    }

    public function environment(): string
    {
        return 'fire';
    }

    public function render(string $page, array $data, int $status): Response
    {
        $view = self::pageView($page);

        if ($view !== null && $this->views !== null && $this->views->exists($view)) {
            return Response::html($this->views->render($view, $data), $status);
        }

        if ($view !== null && $this->components instanceof ComponentRenderer && !$this->components->has($page)) {
            throw new RuntimeException(sprintf(
                'Unknown Firefiber page [%s]: expected template [%s.sahil.php] under the configured view roots '
                .'or a component named [%s].',
                $page,
                str_replace('.', '/', $view),
                $page,
            ));
        }

        return Response::html($this->components->renderComponent($page, $data), $status);
    }

    /**
     * Map a page name to its template under `firefiber/pages`.
     *
     * Class-style names (containing a namespace separator) can only be
     * components, so they return null and skip the template lookup.
     */
    private static function pageView(string $page): ?string
    {
        $name = trim($page);

        if ($name === '' || str_contains($name, '\\')) {
            return null;
        }

        $normalized = str_replace('/', '.', $name);

        return str_starts_with($normalized, 'firefiber.')
            ? $normalized
            : 'firefiber.pages.'.$normalized;
    }
}
