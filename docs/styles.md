# Component styles

Firefiber components and pages can ship their own CSS. Styles travel with
the component's markup — there is no separate build step — and ordinary
component CSS is automatically scoped so it cannot leak into unrelated
parts of the page.

## Scoped styles

Declare `styles()` (or `styleFile()`) on a component. At render time every
selector is rewritten beneath the component's `[data-fx]` scope attribute:

```php
final class UserCard extends Component
{
    protected function styles(): string
    {
        return '.card { display: flex; } .card:hover { box-shadow: 0 2px 8px #0002; }';
    }
}
```

renders once per page as

```html
<section data-firefiber-component="ff-…" data-fx="fx-a1b2c3d4e5" …>
  <style data-firefiber-style="fx-a1b2c3d4e5">
    [data-fx="fx-a1b2c3d4e5"] .card { display: flex; }
    [data-fx="fx-a1b2c3d4e5"] .card:hover { box-shadow: 0 2px 8px #0002; }
  </style>
  …component markup…
</section>
```

The scope identifier derives from the component *class*
(`fx-` + a stable hash), so every instance of the same component shares
one style tag — two `UserCard`s on a page emit it once. Different
components get different scopes, and nested components keep independent
scopes because the attribute sits on each component root.

## Stylesheet files

Larger stylesheets live under `resources/` and are referenced relative to
that directory:

```php
protected function styleFile(): ?string
{
    return 'css/firefiber/user-card.css';
}
```

The file is scoped exactly like `styles()`. Paths are confined to the
application `resources` directory — `..` segments, absolute paths and
drive letters are rejected, and missing files throw rather than silently
dropping styles. In the starter kit, `resources/css/` is also where Vite
entry CSS lives, so component stylesheets follow the same convention and
stay compatible with Tailwind v4, Bootstrap 5 and plain application CSS
(no extra tooling is involved — the file is read, scoped and inlined).

## What the scoper preserves

The stylesheet is parsed as a token stream, not regex-mangled:

- Selector lists, combinators, pseudo-classes/elements and attribute
  selectors (including commas inside `:not()` and quoted attribute
  values) are scoped per branch.
- `@media`, `@supports`, `@layer`, `@container`, `@scope` and
  `@starting-style` recurse — inner rules are scoped.
- `@keyframes`, `@font-face`, `@font-feature-values`, `@page`,
  `@counter-style` and `@property` pass through untouched.
- `html`, `body` and `:root` selectors map onto the scope itself.
- Comments and string contents (including `;`/`{}` inside `content:`)
  are preserved verbatim.

## Reactive updates

Style tags ride inside the component root, so server-driven re-renders
carry them through `innerHTML` swaps. The runtime removes duplicate
`data-firefiber-style` tags after every swap — a component's CSS is never
injected twice, and the first tag for each scope key wins.

## Global styles

Global CSS is explicit opt-in via `globalStyles()`:

```php
protected function globalStyles(): string
{
    return '.theme-banner { position: fixed; inset-inline: 0; }';
}
```

It renders unscoped under a `global:` style key, still deduplicated per
page. Reach for it only for genuinely document-wide rules — everything
else should be scoped (or live in the application's own stylesheet).

## Pages

`Page` inherits all of it: `styles()`, `styleFile()` and `globalStyles()`
work identically on routed pages, with styles injected into the document
`<head>` on the initial render and re-emitted on fragment swaps.
