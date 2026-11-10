# SahilPHP Firefiber

HTML-first, event-driven reactivity for SahilPHP applications.

## Installation

> [!WARNING]
> This package is a **library**, not a project skeleton.
> `composer create-project sahilphp/firefiber` fails by design.
> Create a project with `sahilphp new my-app` and choose **Firefiber** during
> setup; the installer wires the client runtime and your CSS framework together.
>
> Install it as a dependency with:
>
> ```bash
> composer require sahilphp/firefiber
> ```

Create a new application with the installer:

```bash
sahilphp new my-app --ui-kit=firefiber --css=tailwind
cd my-app
```

The generated project already registers `Sahil\Firefiber\FirefiberServiceProvider`
in `bootstrap/providers.php`, so pages, components, the action endpoint and the
browser assets work without further configuration.

### Requirements

- PHP 8.3 or newer.
- `sahilphp/framework` **1.1 or newer**, with the view-layer component renderer
  (`Sahil\Contracts\View\ComponentRendererContract`) and `<fiber:component>`
  template support. Framework releases before this contract do not compile
  component directives and are not compatible.

## Commands

Generate pages and reusable reactive components through the standard
`php sahil` entry point:

```bash
php sahil firefiber:page Demo --example
php sahil firefiber:component Counter
php sahil firefiber:component Counter --inline
php sahil firefiber:component Admin/Stats
```

Pages are discovered from `App\Firefiber\Pages`; the provider registers
their routes automatically. Components are discovered through their class
names in the secure action protocol. Both `<fiber:component>` and `@component`
syntaxes are supported — see [the command reference](docs/commands.md) for
details on both.

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

Short names resolve below `App\Firefiber\Components` (nested names such as
`Admin/Stats` or `Admin\Stats` work too). Markup is produced by `PageRenderer`,
including the signed hydration snapshot, and no directive, tag or marker
comment is left in the response.

Component properties are passed as attributes. Kebab-case attributes map to
camelCase properties, and a leading `:` binds a template expression instead of
a literal:

```html
<fiber:component name="Profile" user-id="42" title="My Profile" />
<fiber:component name="Profile" :user-id="user.id" />
```

Properties are validated against the component's `$propertyDefinitions`; an
undeclared or invalid property is rejected. Each tag renders an independent
instance with its own identity and state, and components may nest through
`children()`. An unknown component, a non-component class, or a component that
reaches itself through `children()` fails with a clear error.

## Runtime

The provider registers:

- `POST /firefiber` for snapshot-signed, CSRF-protected component actions;
- `POST /firefiber/uploads` for secure multipart uploads (CSRF self-validated, snapshot-authorized);
- `GET /firefiber/assets/{asset}` for the package-managed browser assets;
- `App\Firefiber\Pages` discovery for page routes.

The generated component root includes a signed snapshot, session CSRF token,
and the component class name. It never includes a client signing secret. The
browser runtime sends the component name with every action so the server can
hydrate the correct class without a manual factory; the server verifies that
class against its own metadata rather than trusting the client value.

Advanced capabilities (opt-in): URL-synchronized state, server-driven tables,
polling, lazy loading, optimistic UI, secure uploads, and render caching.
See [the components guide](docs/components.md) for properties, events,
forms, validation, lifecycle, and security details.

## Development

```bash
npm run dev          # Vite on http://127.0.0.1:5173
php sahil start      # PHP on http://127.0.0.1:8000
```
