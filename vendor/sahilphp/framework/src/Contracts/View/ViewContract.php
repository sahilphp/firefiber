<?php

declare(strict_types=1);

namespace Sahil\Contracts\View;

/**
 * The view rendering API an application codes against.
 *
 * A caller hands in a template NAME (dotted or slashed, resolved through the
 * configured view directories) and a data map, and gets rendered markup back:
 *
 *     $html = $views->render('pages.home', ['title' => 'Home', 'posts' => $posts]);
 *     return Response::html($html);
 *
 * Everything else — escaping, inheritance, sections, partials, compilation
 * and caching — is the engine's business, and a template never receives the
 * caller's scope: it can only read the keys it was handed.
 *
 * Failures are typed, never silent: a missing template throws
 * {@see \Sahil\View\ViewNotFoundException}, a broken template throws
 * {@see \Sahil\View\ViewSyntaxException} (with template name and line), and a
 * template that compiles but cannot render throws
 * {@see \Sahil\View\ViewRenderException}. All three extend
 * {@see \Sahil\View\ViewException}.
 */
interface ViewContract
{
    /**
     * Render a template to markup (a full page when the template extends a
     * layout).
     *
     * @param array<string, mixed> $data values the template may read
     */
    public function render(string $view, array $data = []): string;

    /**
     * Whether a template name resolves to a file.
     *
     * Returns false for a malformed name (including traversal attempts)
     * rather than throwing, which makes this safe for feature detection:
     * `render()` remains the strict path.
     */
    public function exists(string $view): bool;

    /**
     * The view directories being searched, in order.
     *
     * @return list<string>
     */
    public function paths(): array;

    /**
     * Delete every compiled template (deploy-time reset).
     *
     * @return int number of compiled files removed
     */
    public function flush(): int;
}
