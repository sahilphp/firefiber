<?php

declare(strict_types=1);

namespace Sahil\View;

use Stringable;

/**
 * A rendered template plus the information needed to hand it to a frontend
 * framework: the original template name, the data it was rendered with, the
 * markup it produced, and the rendering context (engine and resolved file).
 *
 * The value object exists so a response never has to carry only a string:
 * a frontend adapter can resolve an entry component from the original
 * template and receive the same PHP data the template used, without
 * re-parsing rendered HTML.
 *
 * It is immutable, and `(string) $view` returns the markup, so existing code
 * that expects rendered HTML keeps working unchanged.
 */
final class RenderedView implements Stringable
{
    /**
     * @param array<string, mixed> $data the exact data handed to the template
     * @param array<string, mixed> $context engine/rendering context (engine name, resolved path, …)
     */
    public function __construct(
        private readonly string $template,
        private readonly array $data,
        private readonly string $markup,
        private readonly array $context = [],
    ) {
    }

    /**
     * A rendered view whose template name is unknown (e.g. markup from a
     * cache or a plain string). Frontend rendering rejects it, because an
     * adapter cannot resolve an entry component without the template.
     */
    public static function fromMarkup(string $markup, array $context = []): self
    {
        return new self('', [], $markup, $context);
    }

    public function template(): string
    {
        return $this->template;
    }

    public function hasTemplate(): bool
    {
        return $this->template !== '';
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return $this->data;
    }

    public function markup(): string
    {
        return $this->markup;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    public function contextValue(string $key, mixed $default = null): mixed
    {
        return $this->context[$key] ?? $default;
    }

    public function __toString(): string
    {
        return $this->markup;
    }
}
