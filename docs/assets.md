# Package-managed assets

Firefiber ships its browser runtime and optional state stylesheet inside the
installed Composer package. Nothing is copied into the application's
`public/`, `resources/` or other application directories.

## Register the provider

SahilPHP does not auto-discover Composer providers, so register the package
provider explicitly in the application's `bootstrap/providers.php`:

```php
\Sahil\Firefiber\FirefiberServiceProvider::class,
```

Register Firefiber after the application's asset provider (for example
`App\Providers\AssetProvider`) so the alias decorator can wrap it. In the
normal initial boot this order is not required, but it also keeps
`Application::reset()` correct for long-running workers.

The provider registers:

- `GET|HEAD /firefiber/assets/{asset}` (route name `firefiber.assets`);
- `@fiberscripts` → the production JavaScript runtime;
- `@fiberstyles` → the production CSS stylesheet;
- `Sahil\Firefiber\Assets\AssetRepository` as `firefiber.assets`;
- `Sahil\Firefiber\Assets\FirefiberAssetManager` as
  `firefiber.assets.manager`;
- the existing `Sahil\Firefiber\Http\FirefiberMiddleware`.

## Alias usage

Use the framework's existing asset directives. No new template syntax is
introduced:

```sahil
@asset_js('@fiberscripts')
@asset_css('@fiberstyles')
```

The alias manager only intercepts those exact Firefiber names. Every other
entry, including `main` and application-defined aliases, is delegated
unchanged to the application's `AssetManagerContract`.

Example output:

```html
<script src="/firefiber/assets/runtime.min.js?v=<content-hash>"
        integrity="sha384-..."
        crossorigin="anonymous"
        defer></script>
<link rel="stylesheet"
      href="/firefiber/assets/firefiber.min.css?v=<content-hash>"
      integrity="sha384-..."
      crossorigin="anonymous">
```

The script alias points at the optimized runtime. The runtime still starts
only through the existing explicit `Firefiber.boot()` call; no directives or
runtime behaviour change.

Available direct asset names (mainly for diagnostics) are:

- `/firefiber/assets/runtime.min.js` — optimized release runtime
- `/firefiber/assets/runtime.js` — readable development source
- `/firefiber/assets/firefiber.min.css` — optimized stylesheet
- `/firefiber/assets/firefiber.css` — readable CSS source

Only the aliases are registered for template consumption. Direct names remain
allow-listed but are not advertised as integration points.

## Build production assets

```sh
npm install
npm run assets:build       # writes dist/runtime.min.js, dist/firefiber.min.css
npm run assets:check       # verifies the checked-in build is current
npm run assets:build:obfuscated
```

The obfuscated mode requires `javascript-obfuscator` and applies to JavaScript
only. CSS is minified; PHP is never minified or obfuscated. Builds are
release/build steps; requests only serve existing allow-listed files.

`dist/`, `css/` and `js/` are included by `composer.json` archive rules and
`.gitattributes`, so they ship in Composer archives and GitHub release
archives. Keep the generated `dist/` files tracked in the release commit.

## Security notes

Only names in `AssetRepository`'s explicit catalog are resolvable. Alias names
are a separate explicit allowlist, and unknown `@fiber…` aliases are rejected
rather than delegated. Names are validated, unknown names return `404`, and
resolved paths must stay inside the package root. Error responses never
include filesystem paths.

Versioned URLs use the current content hash and receive
`Cache-Control: public, max-age=31536000, immutable`. Unversioned URLs are
revalidated through `ETag` and `Last-Modified`. Correct MIME types are set for
JavaScript and CSS.

Minification and optional obfuscation make downloaded JavaScript harder to
read, but they do not prevent a browser user from inspecting, modifying or
replaying client-side assets. Never put secrets in browser code.
