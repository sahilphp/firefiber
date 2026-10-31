# Package-managed assets and aliases

Firefiber ships its browser runtime and its optional stylesheet inside the
installed Composer package. Nothing is copied into the application's
`public/`, `resources/` or other application directories, and the application
never needs to know a filesystem path.

## Aliases

Firefiber registers two package-owned template aliases:

| Alias | Resolves to | Type |
| --- | --- | --- |
| `@fiberscripts` | `dist/runtime.min.js` (optimized production runtime) | JavaScript |
| `@fiberstyles` | `dist/firefiber.min.css` (optimized production stylesheet) | CSS |

The alias registry is an explicit allowlist in
`Sahil\Firefiber\Assets\AssetRepository`. Unknown `@fiber…` names are rejected
instead of being delegated to application tooling, and an alias cannot be
emitted as the wrong tag type.

## Register the provider

SahilPHP does not auto-discover Composer providers, so register the package
provider explicitly in the application's `bootstrap/providers.php`:

```php
Sahil\Firefiber\FirefiberServiceProvider::class,
```

During `boot()` the provider registers the aliases with the application's
existing `AssetManagerContract` and registers:

- `GET|HEAD /firefiber/assets/{asset}` (route name `firefiber.assets`);
- `Sahil\Firefiber\Assets\AssetRepository` as `firefiber.assets`;
- `Sahil\Firefiber\Assets\RuntimeAssets`;
- the existing `Sahil\Firefiber\Http\FirefiberMiddleware`.

The alias decorator intercepts only the two Firefiber aliases. Every other
entry, including application aliases and `main`, is delegated unchanged to
the application asset manager, so existing frontend tooling keeps working.

## Use the aliases

Use the framework's existing asset directives, exactly as for any other entry
point:

```php
@asset_js('@fiberscripts')
@asset_css('@fiberstyles', ['media' => 'screen'])
```

That emits content-versioned tags with Subresource Integrity:

```html
<script src="/firefiber/assets/runtime.min.js?v=<content-hash>"
        integrity="sha384-..."
        crossorigin="anonymous"
        defer></script>
<link rel="stylesheet"
      href="/firefiber/assets/firefiber.min.css?v=<content-hash>"
      integrity="sha384-..."
      crossorigin="anonymous"
      media="screen">
```

No template syntax was added: these are the same `@asset_js` / `@asset_css`
directives the framework already provides.

Firefiber's own helper is also available when a layout is rendered from PHP
rather than a template:

```php
<?= app()->container()->make(\Sahil\Firefiber\Assets\RuntimeAssets::class)->scriptTag() ?>
```

The runtime still starts only through the existing explicit `Firefiber.boot()`
call. Alias loading does not change runtime behavior, override global
JavaScript APIs, or add polling or network requests.

The stylesheet is an optional, layout-neutral baseline. Applications that use
their own state classes can omit `@fiberstyles`; the JavaScript runtime works
without it.

## Delivery

Assets are read from the installed package and streamed by
`Sahil\Firefiber\Http\AssetController`. The route parameter is a catalog key,
never a filesystem path:

- only names in the explicit catalog can be served;
- resolved paths must stay inside the package root (symlinks are checked);
- unknown names return `404`, and missing builds return a generic `503`;
- responses never contain filesystem paths;
- JavaScript is served as `application/javascript; charset=utf-8` and CSS as
  `text/css; charset=utf-8`, always with `X-Content-Type-Options: nosniff`.

Versioned URLs (`?v=<content-hash>`) receive
`Cache-Control: public, max-age=31536000, immutable`. Unversioned URLs are
briefly cacheable and revalidate through `ETag` and `Last-Modified`.
Conditional requests return `304`. Error responses use `no-store`.

## Build production assets

The readable sources live in `js/firefiber.js` and `css/firefiber.css`. The
generated artifacts live in `dist/`:

```sh
npm install
npm run assets:build       # minify JS and CSS, write dist/ + manifest.json
npm run assets:check       # verify the checked-in build is current
npm run assets:build:obfuscated
```

`--obfuscate` requires the optional `javascript-obfuscator` development
dependency and applies only to the generated JavaScript. PHP files are never
minified or obfuscated. Builds are release/build steps; an HTTP request only
serves an existing allow-listed file.

`dist/`, `js/` and `css/` are included in Composer and GitHub release
archives by `composer.json` archive rules and `.gitattributes`. Keep `dist/`
tracked in the release commit.

## Security notes

Minification and optional obfuscation make the downloaded JavaScript harder to
read, but they cannot prevent a browser user from inspecting, modifying or
replaying client-side code. They are not a security boundary: never put
secrets or server-only authorization decisions in browser code.
