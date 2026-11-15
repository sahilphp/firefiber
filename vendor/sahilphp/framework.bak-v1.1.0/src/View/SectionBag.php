<?php

declare(strict_types=1);

namespace Sahil\View;

/**
 * Collected `@section` content, shared by every template in one render
 * (a partial included from a layout appends to the same bag).
 *
 * Two phases make inheritance behave predictably:
 *
 *  - **child phase** (the template being rendered, and its includes):
 *    section bodies are captured; `@parent` marks a section as wanting the
 *    layout's default content;
 *  - **layout phase** (`@extends` target and above): a section body that
 *    the layout declares is DISCARDED when the child already defined that
 *    section without `@parent` (the child wins), PREPENDED when the child
 *    asked for `@parent`, and kept as the default when the child said
 *    nothing about it.
 *
 * Section bodies are captured with output buffering, so nested sections and
 * sections inside loops work the same way any other output does.
 */
final class SectionBag
{
    /** @var array<string, list<string>> captured chunks per section, in order */
    private array $chunks = [];

    /** @var array<string, bool> sections whose child asked for @parent */
    private array $parentsRequested = [];

    /** @var list<string> names of sections whose capture is currently open */
    private array $open = [];

    private bool $layoutPhase = false;

    /**
     * Begin capturing a section body.
     */
    public function start(string $name, bool $parent = false): void
    {
        if ($parent) {
            $this->parentsRequested[$name] = true;
        }

        $this->open[] = $name;

        ob_start();
    }

    /**
     * Mark the section currently being captured as wanting the parent
     * (layout) content in front of its own.
     */
    public function requireParent(): void
    {
        $current = end($this->open);

        if ($current === false) {
            throw new ViewRenderException('@parent must appear inside a @section block.');
        }

        $this->parentsRequested[$current] = true;
    }

    /**
     * Finish the innermost open section and store its content.
     */
    public function end(): void
    {
        $name = array_pop($this->open);

        if ($name === null) {
            throw new ViewRenderException('@endsection without a matching @section.');
        }

        $content = ob_get_clean();

        if (!is_string($content)) {
            $content = '';
        }

        $hasContent = isset($this->chunks[$name]);
        $wantsParent = $this->parentsRequested[$name] ?? false;

        if ($this->layoutPhase && $hasContent && !$wantsParent) {
            // The child already provided this section without asking for the
            // parent's default: the layout's own body is ignored.
            return;
        }

        if ($this->layoutPhase && $wantsParent) {
            // `@parent`: the layout's body leads, the child's content follows.
            $this->chunks[$name] = [$content, ...($this->chunks[$name] ?? [])];

            return;
        }

        $this->chunks[$name][] = $content;
    }

    /**
     * Whether a section was defined by anything in this render.
     */
    public function has(string $name): bool
    {
        return isset($this->chunks[$name]);
    }

    /**
     * Section content for `@yield`: everything captured, joined. The result
     * is template-authored markup that has already been escaped where it
     * needed to be, so it is emitted raw by the caller.
     */
    public function render(string $name, string $default = ''): string
    {
        return isset($this->chunks[$name])
            ? implode('', $this->chunks[$name])
            : $default;
    }

    public function enterLayoutPhase(): void
    {
        $this->layoutPhase = true;
    }

    public function isLayoutPhase(): bool
    {
        return $this->layoutPhase;
    }

    /**
     * Sections still open at the end of a render — a compile-time unclosed
     * block is caught by the compiler, so this only fires when a section was
     * started and never ended through a dictionary the compiler could not
     * see (defensive).
     *
     * @return list<string>
     */
    public function openSections(): array
    {
        return $this->open;
    }
}
