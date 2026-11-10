# Firefiber commands

The package exposes its generators through the normal SahilPHP console:

```bash
php sahil firefiber:page <Name> [options]
php sahil firefiber:component <Name> [options]
```

A starter application generated with the Firefiber UI kit has these command
classes listed in `bootstrap/commands.php`. Equivalent commands are package
commands, so the implementation is loaded from `vendor/sahilphp/firefiber`
and no console implementation is copied into the application.

## Pages

```bash
php sahil firefiber:page Demo
php sahil firefiber:page Demo --example
php sahil firefiber:page Admin/Dashboard --route=/admin/dashboard
```

Options:

- `--route=PATH` — HTTP path for the generated page.
- `--components=Hero,Stats` — compose existing component classes.
- `--example` — also generate `Hero` and `Stats` and compose both.
- `--force` — replace existing files after backing them up.
- `--path=DIR` — application root.

A generated page is written to `App\Firefiber\Pages` and its view to
`resources/views/firefiber/pages`. The provider discovers the page by
namespace and registers its route automatically; no route file edit is
needed.

## Components

```bash
php sahil firefiber:component Counter
php sahil firefiber:component Counter --inline
php sahil firefiber:component Admin/Stats
```

### Default: Standalone component

Without the `--inline` option, the generator creates a standalone component:

```bash
php sahil firefiber:component UserProfile
```

This generates a standalone `Sahil\Firefiber\Component` subclass with its own
file in `app/Firefiber/Components/`, complete with server-side state,
`render()` method, and action handlers.

### Explicit inline generation

Use `--inline` to generate an inline component:

```bash
php sahil firefiber:component UserProfile --inline
```

This writes a component intended to be rendered directly inside a page view
rather than composed through the page's `components()` method. It is the same
`Sahil\Firefiber\Component` subclass contract and the same PSR-4 file layout
(`app/Firefiber/Components/UserProfile.php`), so it can be inserted with either
template syntax below.

### Options

- `--force` — replace existing files after backing them up.
- `--path=DIR` — application root.
- `--inline` — generate an inline component (default: standalone).

Nested names create nested namespaces and directories.

## Component template syntaxes

Both syntaxes are supported in any `.sahil.php` view and compile to the same
call, so a component renders identically either way:

```html
<!-- Firefiber HTML tag syntax (self-closing) -->
<fiber:component name="UserProfile" />

<!-- Firefiber HTML tag syntax (open/close) -->
<fiber:component name="UserProfile"></fiber:component>

<!-- PHP directive -->
@component('UserProfile')

<!-- Fully-qualified class name, both syntaxes -->
<fiber:component name="App\Firefiber\Components\UserProfile" />
@component('App\Firefiber\Components\UserProfile')
```

Short names resolve below `App\Firefiber\Components`; nested names such as
`Admin/Stats` or `Admin\Stats` resolve to the matching sub-namespace. Markup is
produced by `PageRenderer`, so the signed hydration snapshot, CSRF token and
action endpoint are identical for both syntaxes. No directive, tag or marker
comment survives into the response.

Component properties are passed as attributes. Kebab-case attributes map to
camelCase properties, and a leading `:` binds a template expression:

```html
<fiber:component name="Profile" user-id="42" title="My Profile" />
<fiber:component name="Profile" :user-id="user.id" />
```

Properties are validated against the component's `$propertyDefinitions`; an
undeclared or invalid property is rejected. Each tag creates an independent
instance with its own identity and state, and components may nest through
`children()`. An unknown component, a class that does not extend
`Sahil\Firefiber\Component`, or a component that reaches itself through
`children()` fails with a clear error instead of rendering a placeholder.

## Security

Names are validated, path traversal is rejected, existing files are kept
unless `--force` is explicit, and generated PHP is syntax-checked before it
is installed. The browser does not receive the master HMAC key or any client
signing secret.
