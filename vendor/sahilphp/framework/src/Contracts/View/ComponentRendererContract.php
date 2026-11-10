<?php

declare(strict_types=1);

namespace Sahil\Contracts\View;

/**
 * Renders a server-driven UI component from a template.
 *
 * The template engine knows nothing about any particular component library:
 * a template may ask for a component by name and an implementation supplied
 * by the application (for example Firefiber) decides what that name means,
 * instantiates the component, and returns finished markup including any
 * hydration metadata the browser runtime needs.
 *
 * Binding this contract is optional. Templates that do not use `@component`
 * or `<fiber:component>` never resolve it.
 */
interface ComponentRendererContract
{
    /**
     * Render one component instance.
     *
     * @param string $name Component name: a short class name (`Counter`) or a
     *                     fully-qualified class name (`App\Firefiber\Components\Counter`).
     * @param array<string, mixed> $attributes Component properties, keyed by
     *                     property name (kebab-case template attributes are
     *                     converted to camelCase before they arrive here).
     *
     * @throws \Throwable when the component cannot be resolved or rendered,
     *                    so an unknown component fails loudly instead of
     *                    leaving a marker in the response.
     */
    public function renderComponent(string $name, array $attributes = []): string;
}
