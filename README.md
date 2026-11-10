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

## Commands

Generate pages and reusable reactive components through the standard
`php sahil` entry point:

```bash
php sahil firefiber:page Demo --example
php sahil firefiber:component Counter
php sahil firefiber:component Admin/Stats
```

Pages are discovered from `App\Firefiber\Pages`; the provider registers
their routes automatically. Components are discovered through their class
names in the secure action protocol. See [the command reference](docs/commands.md).

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
