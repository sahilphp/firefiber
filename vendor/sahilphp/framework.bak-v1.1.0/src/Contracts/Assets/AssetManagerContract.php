<?php

declare(strict_types=1);

namespace Sahil\Contracts\Assets;

/**
 * The asset API a view or controller codes against.
 *
 * The manager turns profile entry points into safe HTML tags. Templates never
 * have to know whether an asset is served by the Vite development server or
 * from a production build directory: `css()` and `js()` choose the correct
 * URL for the current mode.
 *
 * `main` is the active profile's entry point. Use it in layouts so a template
 * works unchanged with Tailwind, Bootstrap, both, or plain CSS. An explicit
 * entry (`tailwind`, `bootstrap`, `app`) is available for pages that need a
 * particular framework, but the manager refuses an entry the active profile
 * does not include. That is what prevents a Tailwind-only installation from
 * accidentally loading Bootstrap.
 *
 * URLs and attribute values are HTML-escaped by the implementation; callers
 * pass unescaped paths and attribute values.
 */
interface AssetManagerContract
{
    /**
     * Active profile value (`tailwind`, `bootstrap`, `both`, `none`) or null
     * when no profile can be determined.
     */
    public function profile(): ?string;

    /**
     * Whether asset tags point at the Vite development server.
     */
    public function isDevelopment(): bool;

    /**
     * Public URL for an application file under `public/`, e.g.
     * `publicUrl('css/reports.css')` → `/css/reports.css`.
     */
    public function publicUrl(string $path): string;

    /**
     * Stylesheet tag(s) for an entry point.
     *
     * `main` emits every CSS entry the active profile needs. For `both` that
     * is two tags (Tailwind and Bootstrap), because both frameworks were
     * explicitly selected.
     *
     * @param array<string, bool|int|float|string|\Stringable|null> $attributes
     *        extra attributes such as `media`, `nonce` or `crossorigin`;
     *        event-handler attributes and `rel`/`href` are rejected
     */
    public function css(string $entry = 'main', array $attributes = []): string;

    /**
     * Module script tag(s) for an entry point.
     *
     * Profiles without JavaScript (`tailwind`, `none`) return an empty
     * string for `main`.
     *
     * @param array<string, bool|int|float|string|\Stringable|null> $attributes
     *        extra attributes such as `nonce` or `crossorigin`;
     *        event-handler attributes and `src`/`type` are rejected
     */
    public function js(string $entry = 'main', array $attributes = []): string;

    /**
     * The Vite HMR client tag in development, or an empty string in
     * production. A layout includes this once, before its entry tags.
     */
    public function developmentClient(): string;
}
