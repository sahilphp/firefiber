<?php

declare(strict_types=1);

namespace Sahil\Firefiber;

use RuntimeException;
use Sahil\Console\Generator\ApplicationPaths;
use Sahil\Contracts\Foundation\ApplicationContract;
use Sahil\Contracts\View\ComponentRendererContract;

/**
 * Resolves component names used in templates and renders them.
 *
 * Both template syntaxes funnel through here, so a component behaves
 * identically whether it is written as `<fiber:component name="Counter" />`
 * or `@component('Counter')`:
 *
 * - the name is a short class name below `App\Firefiber\Components`
 *   (`Counter`, `Admin\Stats`), or a fully-qualified class name;
 * - the class must extend {@see Component} — a template can never make the
 *   server instantiate an arbitrary class;
 * - component properties arrive as an array and are validated by
 *   {@see Component::setProperties()};
 * - markup is produced by {@see PageRenderer::renderComponent()}, which adds
 *   the signed hydration snapshot the browser runtime needs for actions.
 */
final class ComponentRenderer implements ComponentRendererContract
{
    public function __construct(
        private readonly ApplicationContract $application,
    ) {
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function renderComponent(string $name, array $attributes = []): string
    {
        $component = $this->make($name);

        if ($attributes !== []) {
            $component->setProperties($attributes);
        }

        return $this->renderer()->renderComponent($component);
    }

    /**
     * Resolve the page renderer on first use.
     *
     * PageRenderer itself depends on the view engine, which depends on this
     * renderer; resolving eagerly here would make the container build a cycle.
     */
    private function renderer(): PageRenderer
    {
        /** @var PageRenderer $renderer */
        $renderer = $this->application->container()->make(PageRenderer::class);

        return $renderer;
    }

    /**
     * Resolve a component name to an instantiated component.
     *
     * @param array<string, mixed> $properties
     */
    public function make(string $name, array $properties = []): Component
    {
        $class = $this->resolveClass($name);
        $component = $this->instantiate($class);

        if ($properties !== []) {
            $component->setProperties($properties);
        }

        return $component;
    }

    /**
     * Map a template component name to an existing Component subclass.
     */
    public function resolveClass(string $name): string
    {
        $name = trim($name);

        if ($name === '') {
            throw new RuntimeException('A Firefiber component name cannot be empty.');
        }

        $class = $this->qualify($name);

        if (!class_exists($class)) {
            throw new RuntimeException(sprintf(
                'Unknown Firefiber component [%s]; expected class [%s]. '
                .'Generate one with "php sahil firefiber:component %s".',
                $name,
                $class,
                $name,
            ));
        }

        if (!is_subclass_of($class, Component::class)) {
            throw new RuntimeException(sprintf(
                'Firefiber component [%s] resolves to [%s], which does not extend %s.',
                $name,
                $class,
                Component::class,
            ));
        }

        return $class;
    }

    /**
     * The namespace component names are looked up in.
     */
    public function componentsNamespace(): string
    {
        try {
            $paths = ApplicationPaths::for((string) $this->application->basePath());

            return $paths->applicationNamespace().'\\Firefiber\\Components';
        } catch (\Throwable) {
            return 'App\\Firefiber\\Components';
        }
    }

    /**
     * `Counter` and `Admin\Stats` are relative to the components namespace; a
     * name that already starts with the application namespace is used as-is.
     */
    private function qualify(string $name): string
    {
        $name = ltrim($name, '\\');
        $namespace = $this->componentsNamespace();

        if (str_starts_with($name, $namespace.'\\') || class_exists($name)) {
            return $name;
        }

        // Relative names, including nested ones such as `Admin\Stats`.
        return $namespace.'\\'.$name;
    }

    private function instantiate(string $class): Component
    {
        try {
            $component = $this->application->container()->make($class);
        } catch (\Throwable) {
            $component = new $class();
        }

        if (!$component instanceof Component) {
            throw new RuntimeException(sprintf(
                'Firefiber component [%s] did not resolve to a %s instance.',
                $class,
                Component::class,
            ));
        }

        return $component;
    }
}
