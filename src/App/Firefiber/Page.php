<?php

declare(strict_types=1);

namespace Sahil\Firefiber;

use Sahil\Http\Request;
use Sahil\Http\Response;

/**
 * A discoverable Firefiber page.
 *
 * Page classes live below the application's `App\Firefiber\Pages` namespace.
 * The package provider scans that namespace, registers the returned path as a
 * GET route, and resolves the page through the container. A page therefore
 * needs no manual route entry and no edits to framework internals.
 */
abstract class Page
{
    public function __construct(
        protected readonly PageRenderer $renderer,
    ) {
    }

    /**
     * Absolute HTTP path for this page, for example `/firefiber/demo`.
     */
    abstract public function path(): string;

    /**
     * Human-readable page title.
     */
    abstract public function title(): string;

    /**
     * Reactive components composed by this page.
     *
     * @return list<Component>
     */
    abstract public function components(): array;

    /**
     * View name to render, or null to use the package's document fallback.
     */
    public function view(): ?string
    {
        return null;
    }

    public function show(Request $request): Response
    {
        return $this->renderer->render($request, $this);
    }
}
