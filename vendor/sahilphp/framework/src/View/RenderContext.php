<?php

declare(strict_types=1);

namespace Sahil\View;

use ArrayAccess;
use Sahil\Contracts\Assets\AssetManagerContract;

/**
 * The runtime state a compiled template sees as `$__ctx`.
 *
 * It is the ONLY surface a template can reach, which is what keeps the
 * template language from becoming PHP:
 *
 * - data access goes through {@see get()} (dotted path over arrays,
 *   `ArrayAccess` and object properties) — a missing path is `null`, never
 *   an error, so optional values need no guards;
 * - a `@foreach` binding is a SCOPED NAME in the same namespace as data
 *   (`@foreach (posts as post)` makes `post.title` readable), not a PHP
 *   variable, so templates never see or create PHP state. Bindings are
 *   stack-based, so nested loops shadow and then restore correctly;
 * - inheritance state (`extend`/sections) lives here, not in the template;
 * - `@include` re-enters the engine with the current scope, but the SAME
 *   section bag, so partials can contribute to a layout's sections.
 */
final class RenderContext
{
    /** @var array<string, mixed> the data this template was given */
    private array $data;

    /** @var array<string, mixed> visible loop bindings (innermost value wins) */
    private array $locals = [];

    /**
     * Previous state of every pushed binding, so a closing `@endforeach`
     * restores what the enclosing scope had (or removes the name entirely).
     *
     * @var list<array{name: string, had: bool, value: mixed}>
     */
    private array $localStack = [];

    private ?string $parent = null;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        private readonly ViewEngine $engine,
        array $data,
        private readonly SectionBag $sections,
        private readonly int $depth = 0,
        private readonly ?AssetManagerContract $assets = null,
    ) {
        $this->data = $data;
    }

    /* ---------- data ---------- */

    /**
     * Resolve a dotted path (`user.name`, `items.0.name`, `post.title`).
     *
     * Loop bindings are visible first (innermost loop wins), then the data
     * this template was given. Returns `null` for anything that cannot be
     * resolved — including intermediate values that are not arrays/objects.
     * A template never needs an `isset` guard to read an optional value.
     */
    public function get(string $path): mixed
    {
        if ($path === '') {
            return null;
        }

        $segments = explode('.', $path);
        $first = (string) array_shift($segments);

        if (array_key_exists($first, $this->locals)) {
            $current = $this->locals[$first];
        } else {
            $current = self::readSegment($this->data, $first);
        }

        foreach ($segments as $segment) {
            $current = self::readSegment($current, $segment);

            if ($current === null) {
                return null;
            }
        }

        return $current;
    }

    /**
     * Whether a dotted path resolves to a value (a stored `null` counts as
     * present).
     */
    public function has(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        $segments = explode('.', $path);
        $first = (string) array_shift($segments);

        if (array_key_exists($first, $this->locals)) {
            $current = $this->locals[$first];
        } elseif (self::segmentExists($this->data, $first)) {
            $current = self::readSegment($this->data, $first);
        } else {
            return false;
        }

        foreach ($segments as $segment) {
            if (!self::segmentExists($current, $segment)) {
                return false;
            }

            $current = self::readSegment($current, $segment);
        }

        return true;
    }

    /**
     * The data this template was given (loop bindings excluded; they are a
     * scope, not data).
     *
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return $this->data;
    }

    /* ---------- loop bindings ---------- */

    /**
     * Make `$name` readable inside the current loop body.
     */
    public function pushLocal(string $name, mixed $value): void
    {
        $this->localStack[] = [
            'name' => $name,
            'had' => array_key_exists($name, $this->locals),
            'value' => $this->locals[$name] ?? null,
        ];

        $this->locals[$name] = $value;
    }

    /**
     * Drop the last `$count` pushed bindings, restoring whatever they
     * shadowed. Emitted by `@endforeach`.
     */
    public function popLocals(int $count = 1): void
    {
        while ($count-- > 0) {
            $previous = array_pop($this->localStack);

            if ($previous === null) {
                return;
            }

            if ($previous['had']) {
                $this->locals[$previous['name']] = $previous['value'];

                continue;
            }

            unset($this->locals[$previous['name']]);
        }
    }

    /**
     * Visible loop bindings, outermost first.
     *
     * @return array<string, mixed>
     */
    public function locals(): array
    {
        return $this->locals;
    }

    /* ---------- inheritance ---------- */

    /**
     * Declare the layout this template extends (`@extends('layouts.app')`).
     *
     * Only the top-level template may extend: an included partial setting a
     * parent would silently change what the caller renders.
     */
    public function extend(string $name): void
    {
        if ($this->depth > 0) {
            throw new ViewRenderException(sprintf(
                '@extends([%s]) is only allowed in the top-level template; a partial cannot change the layout.',
                $name,
            ));
        }

        $this->parent = $name;
    }

    public function parent(): ?string
    {
        return $this->parent;
    }

    /* ---------- sections ---------- */

    public function startSection(string $name, bool $parent = false): void
    {
        $this->sections->start($name, $parent);
    }

    public function requireParent(): void
    {
        $this->sections->requireParent();
    }

    public function endSection(): void
    {
        $this->sections->end();
    }

    /**
     * `@yield('name')` / `@yield('name', 'default')`. Section content is
     * emitted raw: it was produced by this engine, with `{{ }}` escapes
     * already applied where the template asked for them.
     */
    public function yieldSection(string $name, mixed $default = ''): string
    {
        return $this->sections->render($name, Escaper::raw($default));
    }

    public function hasSection(string $name): bool
    {
        return $this->sections->has($name);
    }

    /* ---------- includes ---------- */

    /**
     * `@include('partial', ['a' => 1])` — rendered with the current scope
     * (data plus visible loop bindings) merged with `$data`, where the given
     * keys win. The section bag is shared, so the partial can fill a layout
     * section.
     *
     * @param array<string, mixed> $data
     */
    public function include(string $name, array $data = []): string
    {
        return $this->engine->renderInclude(
            $name,
            array_replace($this->data, $this->locals),
            $data,
            $this->sections,
            $this->depth + 1,
        );
    }

    public function depth(): int
    {
        return $this->depth;
    }

    /* ---------- assets ---------- */

    /**
     * `asset('css/reports.css')` — public URL for an application file.
     *
     * Used with the normal escaped echo: `href="{{ asset('...') }}"`.
     */
    public function asset(string $path): string
    {
        return $this->assetManager()->publicUrl($path);
    }

    /**
     * `@asset_css('main')` — stylesheet tag(s) for an entry point.
     *
     * @param array<string, bool|int|float|string|null> $attributes
     */
    public function assetCss(string $entry = 'main', array $attributes = []): string
    {
        return $this->assetManager()->css($entry, $attributes);
    }

    /**
     * `@asset_js('main')` — module script tag(s) for an entry point.
     *
     * @param array<string, bool|int|float|string|null> $attributes
     */
    public function assetJs(string $entry = 'main', array $attributes = []): string
    {
        return $this->assetManager()->js($entry, $attributes);
    }

    /**
     * `@asset_client` — Vite HMR client in development, empty in production.
     */
    public function assetClient(): string
    {
        return $this->assetManager()->developmentClient();
    }

    private function assetManager(): AssetManagerContract
    {
        if ($this->assets === null) {
            throw new ViewRenderException(
                'Asset helpers require an AssetManager. Register App\Providers\AssetProvider '
                .'or pass an AssetManagerContract to ViewEngine.',
            );
        }

        return $this->assets;
    }

    /* ---------- segment resolution ---------- */

    private static function readSegment(mixed $current, string $segment): mixed
    {
        if (is_array($current)) {
            return array_key_exists($segment, $current) ? $current[$segment] : null;
        }

        if ($current instanceof ArrayAccess) {
            return $current->offsetExists($segment) ? $current[$segment] : null;
        }

        if (is_object($current)) {
            if (property_exists($current, $segment) || isset($current->{$segment})) {
                return $current->{$segment};
            }

            return null;
        }

        return null;
    }

    private static function segmentExists(mixed $current, string $segment): bool
    {
        if (is_array($current)) {
            return array_key_exists($segment, $current);
        }

        if ($current instanceof ArrayAccess) {
            return $current->offsetExists($segment);
        }

        if (is_object($current)) {
            return property_exists($current, $segment) || isset($current->{$segment});
        }

        return false;
    }
}
