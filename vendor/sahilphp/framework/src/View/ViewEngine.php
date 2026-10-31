<?php

declare(strict_types=1);

namespace Sahil\View;

use Sahil\Contracts\Assets\AssetManagerContract;
use Sahil\Contracts\View\ViewContract;
use Throwable;

/**
 * The template engine: resolves, compiles and evaluates templates.
 *
 * ### Security model
 *
 * A template is data, never code:
 *  - it is PARSED and re-emitted by {@see TemplateCompiler}, so nothing from
 *    a template reaches `eval()`, a variable function, or an include of a
 *    path the template chose;
 *  - it can only read the data it was given, through {@see RenderContext};
 *  - it can only call the {@see ViewHelpers} whitelist;
 *  - output escapes by default, and the raw channel is explicit syntax;
 *  - it can only include names that pass
 *    {@see TemplateResolver}'s segment validation, so a template cannot
 *    traverse out of the view directories.
 *
 * ### Inheritance
 *
 * `@extends` is resolved here, not in the template: the child is rendered
 * first for its sections, then each layout in the chain, and the LAST
 * template in the chain supplies the final output. The chain is cycle-checked
 * and a partial may not introduce a layout (`@extends` in an included
 * template is an error).
 *
 * ### Buffer safety
 *
 * Every evaluate call opens exactly one output buffer and closes it on both
 * the success and the failure path, so a template error can never leave an
 * unbalanced buffer behind (or leak partial output into the response).
 */
final class ViewEngine implements ViewContract
{
    /**
     * Include nesting limit. Reaching it means a template includes itself
     * (directly or through a cycle), which is always a bug.
     */
    public const MAX_INCLUDE_DEPTH = 32;

    public function __construct(
        private readonly TemplateResolver $resolver,
        private readonly TemplateCompiler $compiler,
        private readonly ?AssetManagerContract $assets = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function render(string $view, array $data = []): string
    {
        $sections = new SectionBag();
        $visited = [];
        $current = $view;
        $output = '';

        while (true) {
            if (isset($visited[$current])) {
                throw new ViewRenderException(sprintf(
                    'Circular @extends chain: %s.',
                    implode(' -> ', [...array_keys($visited), $current]),
                ));
            }

            $visited[$current] = true;

            $context = new RenderContext($this, $data, $sections, 0, $this->assets);
            $output = $this->evaluate($current, $context);

            $parent = $context->parent();

            if ($parent === null) {
                break;
            }

            // Child templates only declare sections; the layout produces the
            // markup that survives.
            $sections->enterLayoutPhase();
            $current = $parent;
        }

        return $output;
    }

    public function exists(string $view): bool
    {
        return $this->resolver->exists($view);
    }

    /**
     * @return list<string>
     */
    public function paths(): array
    {
        return $this->resolver->roots();
    }

    public function flush(): int
    {
        return $this->compiler->flush();
    }

    /**
     * Absolute path of the file a template name resolves to. Useful for
     * diagnostics and tests; rendering does not need it.
     */
    public function path(string $view): string
    {
        return $this->resolver->resolve($view);
    }

    /**
     * Absolute path of a template's compiled artifact (whether or not it has
     * been compiled yet).
     */
    public function compiledPath(string $view): string
    {
        return $this->compiler->compiledPath($this->resolver->resolve($view));
    }

    /**
     * Render an included template (`@include`). Called by
     * {@see RenderContext}; application code calls {@see render()}.
     *
     * The included template sees the caller's data merged with the given
     * overrides and shares the render's section bag, so a partial can fill a
     * section declared by a layout.
     *
     * @param array<string, mixed> $data current scope's data
     * @param array<string, mixed> $overrides keys the include passes explicitly
     */
    public function renderInclude(string $name, array $data, array $overrides, SectionBag $sections, int $depth): string
    {
        if ($depth > self::MAX_INCLUDE_DEPTH) {
            throw new ViewRenderException(sprintf(
                '@include nesting exceeded %d levels at [%s]; a template probably includes itself.',
                self::MAX_INCLUDE_DEPTH,
                $name,
            ));
        }

        $context = new RenderContext($this, array_replace($data, $overrides), $sections, $depth, $this->assets);

        return $this->evaluate($name, $context);
    }

    private function evaluate(string $view, RenderContext $context): string
    {
        $compiled = $this->compiler->compileIfNeeded($view, $this->resolver->resolve($view));

        $level = ob_get_level();

        ob_start();

        try {
            (static function (string $__compiled, RenderContext $__ctx): void {
                include $__compiled;
            })($compiled, $context);
        } catch (Throwable $failure) {
            // Drop this template's buffer (and any section capture it left
            // open), then surface the failure.
            while (ob_get_level() > $level) {
                ob_end_clean();
            }

            throw $this->wrap($view, $failure);
        }

        $output = '';

        while (ob_get_level() > $level) {
            $output = ob_get_clean().$output;
        }

        return $output;
    }

    /**
     * View failures keep their own type (they already name the template and
     * line); anything else is wrapped so the caller always knows which
     * template blew up, with the original throwable preserved.
     */
    private function wrap(string $view, Throwable $failure): Throwable
    {
        return $failure instanceof ViewException
            ? $failure
            : ViewRenderException::forTemplate($view, $failure);
    }
}
