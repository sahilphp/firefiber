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

This generates an inline component that is designed to be composed directly
into a page's `components()` method rather than generated as a standalone file.

The generated component is a `Sahil\Firefiber\Component` subclass but is
structured for inline use within pages.

### Options:

- `--force` — replace existing files after backing them up.
- `--path=DIR` — application root.
- `--inline` — generate an inline component (default: standalone).

The generated component is a `Sahil\Firefiber\Component` subclass with
server-side state, a `render()` method, and action handlers. Nested names
create nested namespaces and directories.

## Security

Names are validated, path traversal is rejected, existing files are kept
unless `--force` is explicit, and generated PHP is syntax-checked before it
is installed. The browser does not receive the master HMAC key or any client
signing secret.
