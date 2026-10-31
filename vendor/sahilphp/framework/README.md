# SahilPHP

An original full-stack PHP framework for production applications. Modular,
object-oriented, dependency-injected, and testable.

**Status:** The core framework is implemented — foundation/DI, HTTP kernel &
routing, configuration & error handling, validation, the database layer &
query builder, the ORM, migrations & seeding, the template engine, and the
frontend asset pipeline. See "Roadmap" below for remaining work.

> **Package scope:** This repository is the reusable framework library.
> Application entry points — `public/index.php`, `bootstrap/app.php`,
> `bin/sahil`, and the `start` Composer script — are owned by the
> starter-kit application. This package does not install a CLI binary or a
> web front controller. Every `php bin/sahil …` example in this document is
> therefore a command for the starter-kit (or any application) root, not for
> this package.

## Requirements

- PHP 8.1+ (the version `composer.json` requires)
- Composer 2.x
- Node.js 20.19+ or 22.12+ and a package manager (npm, pnpm, yarn or bun)
  are required **only** for the optional UI asset commands.

**PHP extensions:** one of `pdo_mysql` / `pdo_pgsql` is required for the
database layer, ORM and migrations. Also supported when present: `mbstring`
(the validator is mb-aware). `openssl` is required for TLS/SSL SMTP
(`MAIL_SECURITY=tls` or `ssl`).

## Installation

```bash
composer require sahilphp/framework
```

The package is a library: it declares no binary and no front controller. To
create a runnable application, install the starter kit instead:

```bash
composer create-project sahilphp/starter-kit my-app
```

## Directory layout

This package is a Composer **library** (`"type": "library"`): source under
`src/` and a test suite under `tests/`. It is not an application skeleton. No
front controller, CLI binary, configuration, routes, templates or storage
directory ships with it — the application that requires the package wires
those itself.

### This package

| Path | Purpose |
|------|---------|
| `src/` | Framework source, autoloaded as `Sahil\` (`autoload.psr-4`) |
| `src/Support/helpers.php` | Global helper functions, autoloaded through `autoload.files` |
| `tests/` | PHPUnit suite, autoloaded as `Sahil\Tests\` (`autoload-dev`) |
| `phpunit.xml` | PHPUnit configuration used by `composer test` |
| `composer.json` | Package manifest: requirements, autoloading, quality-gate scripts |
| `composer.lock` | Dependency set the package's tests run against |
| `LICENSE` | MIT license text (see "License" below) |
| `README.md` | This document |
| `.gitignore` | Ignores `vendor/`, `.phpunit.cache/` and local artefacts |

That is the whole tree at the package root. A `vendor/` directory appears after
`composer install`; it is generated locally, git-ignored, and not part of the
published package.

### The starter-kit application (separate repository)

`sahilphp/framework` supplies the behaviour; `sahilphp/starter-kit` is the
application that requires it and owns every runnable path — entry points,
configuration, routes, templates and storage. The paths below exist in **that**
repository only and are **not shipped in this package**; they are listed so
that documentation elsewhere in this README is easy to follow:

| Path (starter-kit root) | Purpose |
|------|---------|
| `app/` | Application code, `App\` namespace: `Http/` controllers, `Providers/` |
| `bin/sahil` | CLI entry point; dispatches `ui:*` to `Sahil\Ui\UiConsole`, then boots the application |
| `bootstrap/app.php`, `bootstrap/providers.php` | Bootstrap entry point (`sahil_bootstrap()`) and provider manifest |
| `config/` | Configuration arrays, one file per namespace (`config/app.php`, …) |
| `database/` | `database/migrations/` and `database/seeders/` |
| `public/` | `public/index.php` HTTP front controller plus served assets (`public/css`, `public/js`, `public/build`) |
| `routes/` | Route table (`routes/web.php`) and scheduled tasks (`routes/schedule.php`) |
| `views/` | Layouts, pages and partials with the `.sahil.php` extension |
| `storage/` | Runtime storage: logs, compiled views, sessions, cache, uploads |
| `resources/` | Frontend sources used by the UI profiles |
| `tests/` | Application test suite, `Tests\` namespace |

Both lists are relative to their own repository root, so the same name can
mean different things: this package's `tests/` is the `Sahil\Tests\` suite,
while the starter-kit's `tests/` is the application's `Tests\` suite.

## Local development

This package is a library. It has no application bootstrap or HTTP server;
those belong to the starter-kit application. From the application root, the
starter-kit owns the CLI (`bin/sahil`) and the development server script.

Run the framework quality gates from this package:

```bash
# Quality gates
composer lint      # php -l on every source and test file
composer test      # PHPUnit
composer analyse   # PHPStan (level 6)
composer check     # lint + test + analyse
composer check-style    # PHP-CS-Fixer dry run
composer fix-style      # PHP-CS-Fixer auto-fix
```

## UI installation & assets

The UI installer and asset pipeline are implemented in this package, but the
`bin/sahil` executable is **not**: `composer.json` declares no `bin` entry and
this repository has no `bin/` directory. The binary belongs to the starter-kit
application, and the pipeline it calls is `Sahil\Ui\UiConsole`. The `ui:*`
commands read the project directory directly, so they work before the
application boots and do not require a database connection.

Every `php bin/sahil ui:*` example below is a command for the **starter-kit
(or any application) root**, where that wrapper exists. In a project without
`bin/sahil`, call the console class from your own entry point:

```php
<?php
require 'vendor/autoload.php';

// command, its arguments, project root; returns the process exit code
exit((new Sahil\Ui\UiConsole())->run('ui:status', [], __DIR__));
```

The command names are listed in `Sahil\Ui\UiConsole::COMMANDS`
(`ui:install`, `ui:dev`, `ui:build`, `ui:status`, `ui:theme`).

**Prerequisites:** Node.js 20.19+ or 22.12+ and one package manager. The
installer detects `pnpm-lock.yaml`, `yarn.lock`, `bun.lockb`/`bun.lock` or
`package-lock.json`; without a lock file it prefers npm. Missing tools are
reported with an install command instead of a stack trace.

### Profiles

| Profile     | What it generates                                                                 | npm packages                                  |
|-------------|-----------------------------------------------------------------------------------|-----------------------------------------------|
| `tailwind`  | `vite.config.js`, `resources/css/tailwind.css`                                    | `vite`, `tailwindcss`, `@tailwindcss/vite`    |
| `bootstrap` | `vite.config.js`, `resources/css/bootstrap.css`, `resources/js/bootstrap.js`      | `vite`, `bootstrap`                           |
| `both`      | Every Tailwind and Bootstrap file, built into separate deterministic outputs       | all of the above                              |
| `none`      | `vite.config.js`, `resources/css/app.css` (plain CSS, no framework)               | `vite`                                        |

All profiles also write `package.json`, `.sahil-ui.json` (the selected
profile and generated file list), and append the required entries to
`.gitignore`. No files from another profile are generated.

### Installing

```bash
# Run from the starter-kit application root.
# Tailwind CSS v4 (installs npm dependencies as well)
php bin/sahil ui:install tailwind

# Bootstrap 5, files only — install dependencies later
php bin/sahil ui:install bootstrap --no-install

# Both frameworks; merge into existing package.json/configuration
php bin/sahil ui:install both --merge

# Plain CSS
php bin/sahil ui:install none
```

`ui:install` is idempotent: identical files are left untouched. If a managed
file already exists with different content, installation **aborts before
writing anything** and lists the conflicts. Choose explicitly:

```bash
php bin/sahil ui:install tailwind --merge      # keep user files; merge package.json + .gitignore
php bin/sahil ui:install tailwind --overwrite  # replace managed files; originals go to
                                               # storage/framework/ui-backups/<timestamp>-<id>/
php bin/sahil ui:install tailwind --dry-run    # print the complete plan, write nothing
```

With `--merge`, existing scripts, dependency pins, metadata and custom
`vite.config.js` files are preserved; only missing package entries and
generated source files are added. `--overwrite` is the only mode that
replaces a conflicting file, and it keeps a backup first. `--force` is an
alias for `--overwrite`.

### Development, build and status

```bash
php bin/sahil ui:dev                       # Vite dev server
php bin/sahil ui:dev --host=0.0.0.0 --port=5199
php bin/sahil ui:build                     # production build
php bin/sahil ui:build --clean             # remove public/build first
php bin/sahil ui:status                    # human-readable readiness report
php bin/sahil ui:status --json             # machine-readable report for CI
```

Build outputs are deterministic because templates reference stable paths:

| Profile     | Build output |
|-------------|--------------|
| `tailwind`  | `public/build/assets/tailwind.css` |
| `bootstrap` | `public/build/assets/bootstrap.css`, `public/build/assets/bootstrap.js` |
| `both`      | all three files |
| `none`      | `public/build/assets/app.css` |

`ui:status` exits `0` only when the profile, generated sources, npm packages
and build outputs are all present and the outputs are newer than their
sources. Missing tooling, packages or assets are listed with the exact
command that fixes them. `ui:install` (dependency installation), `ui:build`
and `ui:dev` propagate the package manager's nonzero exit code and print its
error output.

Commands can target another checkout with `--path=DIR`:

```bash
php bin/sahil ui:install both --path=/srv/apps/example
php bin/sahil ui:build --path=/srv/apps/example
```

External programs are executed as argument vectors through `proc_open()`
with no shell, so profile names, paths and Vite flags cannot become shell
syntax. The implementation lives in [`src/Ui/`](src/Ui/): `UiConsole` parses
arguments and prints the report, `UiInstaller` generates the profile files,
`PackageJsonMerger` holds the merge rules, `ToolchainDetector` detects the
package manager and `StatusReport` covers the `ui:status` checks.

### Optional theme tokens

Theme customization is opt-in. `ui:theme` reads a neutral JSON document,
validates it, and generates framework-specific files for the active profile:

```bash
php bin/sahil ui:theme                    # reads theme/tokens.json
php bin/sahil ui:theme --source=theme/brand.json
php bin/sahil ui:theme --dry-run
php bin/sahil ui:theme --json
```

The first run creates `theme/tokens.json` with a neutral light/dark sample.
Generated files live under `resources/css/*.generated.css` and
`resources/scss/_sahil-theme.generated.scss`; `ui:theme` owns them.
Application overrides stay in `resources/css/theme.overrides.css` and are
never replaced. Tailwind receives `@theme inline` mappings, Bootstrap receives
a runtime CSS-variable bridge plus an optional Sass variable file, and the
plain profile receives the same neutral custom properties.

The generator is a build-time command only. It is not a runtime dependency,
and `ui:install` continues to produce a complete profile without it. The token
format, mapping rules, light/dark behavior and Bootstrap Sass limitations are
defined in [`src/Theme/`](src/Theme/) (`Sahil\Theme\ThemeInstaller`).

### Templates and asset helpers

Templates use the active profile through the runtime asset manager; they
never hand-build development-server or production-build URLs:

```php
@asset_client          {{-- Vite HMR client: development only --}}
@asset_css('main')     {{-- the selected profile's stylesheet(s) --}}
@asset_js('main')      {{-- the selected profile's module script(s) --}}

<link rel="stylesheet" href="{{ asset('css/reports.css') }}">
```

`main` expands to Tailwind, Bootstrap, both, or plain CSS according to
`.sahil-ui.json`. A Tailwind-only profile cannot request a Bootstrap entry,
so the framework never loads both CSS frameworks implicitly. Page-specific
files are added through the `head` and `scripts` sections of the layout.

This package ships no templates: `views/` belongs to the application. The
starter-kit holds the sample layouts for Tailwind, Bootstrap and plain CSS in
its `views/layouts/`, and its `/ui-kit` page demonstrates forms, validation
errors, alerts, a responsive table, shared navigation and responsive grids.

See [`src/Assets/`](src/Assets/) for the service interface and the
`ASSET_PROFILE`, `ASSET_MODE` and `ASSET_URL` configuration keys referenced
above; the starter-kit's `views/layouts/` provides the sample usage.

## Environment

Configuration and environment files belong to the application: this package
ships neither `.env` nor `.env.example`. In the starter-kit root, copy its
`.env.example` to `.env` and adjust per deployment — `.env` is git-ignored
there, so never commit real credentials.

## Container & services (Phase 1–2)

`Sahil\Container\Container` implements PSR-11 and supports:

```php
use Sahil\Foundation\Application;

$application = require __DIR__ . '/../bootstrap/app.php';      // boots and returns Application
$container = $application->container();
$container->bind(InterfaceName::class, Concretion::class);     // transient factory/class binding
$container->singleton(SharedService::class);                   // resolved once per process
$container->scoped(RequestService::class);                     // reset at the end of each request scope
$service = $container->make(SharedService::class);             // auto-wired constructor injection
```

Startup failures (malformed `.env` lines, non-array config files) throw
immediately at boot so misconfiguration can never go silent.

## Lifecycle & scoping (Phase 2)

Three instance lifetimes are supported by `Sahil\Container\Container`:

| Binding method      | Lifetime                         | Typical service        |
|---------------------|----------------------------------|------------------------|
| `bind()`            | new instance per resolution      | stateless strategies   |
| `singleton()`       | one instance per process         | connection managers    |
| `scoped()`          | one instance per request scope   | request context        |

Opening and closing a scope is explicit and leak-proof:

```php
$application->isolatedRequest(function ($scope) use ($container): void {
    // Scoped services are only resolvable while a scope is open.
    $job = $container->make(RequestJob::class);

    // Unless your work throws — in which case the scope still ends,
    // because teardown runs in a `finally` block.
});
```

Rules enforced for long-running workers:

- Resolving a `scoped()` binding with no open scope throws
  `Sahil\Container\BindingException` (stale request state is never
  silently reachable).
- `reset()` flushes containers, re-registers providers and drops every
  scope; its acceptance test loops three sequential requests in one
  process and verifies scoped instances are rebuilt per request while
  singletons persist.
- `Application::reset()` is what worker supervisors call between
  iterations; `Application::free()` releases the process handle entirely.

## HTTP foundation (Phase 3)

`Sahil\Http\Request` / `Sahil\Http\Response` are the stable HTTP surface.
The public entrypoint (`public/index.php`) is the only superglobals
boundary: it snapshots `$_SERVER`/`$_GET`/`$_POST`/`$_COOKIE`/`php://input`
once through `Request::fromGlobals()` and never writes back to them.

### Request

```php
use Sahil\Http\Request;

$request = Request::fromGlobals();
$request->method();        // 'GET' (validated token)
$request->path();          // percent-decoded, no query string
$request->queryParameter('page', '1');
$request->header('User-Agent');  // case-insensitive lookup
$request->cookie('session');

// Body access is explicit:
$request->json();         // decodes, [] on empty; 400 on malformed JSON
$request->form();          // x-www-form-urlencoded / multipart, string-only map

// Trust posture:
$request->clientIp();      // REMOTE_ADDR only — forwarded headers not trusted
$request->scheme();        // HTTPS / REQUEST_SCHEME only
$request->isSecure();
```

Errors from parsing are `Sahil\Http\Exception\*`:

| Exception                          | Status | When                                         |
|------------------------------------|--------|----------------------------------------------|
| `BadRequestHttpException`          | 400    | malformed method/URI, invalid JSON body       |
| `MethodNotAllowedHttpException`    | 405    | unsupported verb; carries `Allow` header      |
| `NotFoundHttpException`            | 404    | unavailable route (router phase)              |

Every `HttpException` carries `statusCode()`, `getHeaders()` and can be
rendered to a response by the application error path.

### Response

```php
use Sahil\Http\Response;

Response::text('hello');                       // text/plain
Response::json(['ok' => true]);                // app/json, lossless encoding
Response::empty();                             // 204, no body
Response::redirect('https://sahil.test', 308); // 301/302/303/307/308 only

$response->withHeader('X-Request-Id', $id);    // immutable derivation
(new \Sahil\Http\Emitter\DefaultResponseEmitter())->emit($response, $request);
```

Guarantees:

- Status 100–599 enforced; 1xx / 204 / 304 must be bodyless (`empty()`
  refuses anything else).
- Header names must be ASCII tokens; values reject CR/LF/NUL — CRLF
  injection through the response API is impossible and throws.
- Redirect statuses are constrained to 3xx codes with `Location`
  validated the same way.
- Emission refuses to run after output has started
  (`HeadersAlreadySentException`).
- HEAD requests emit the full header set (Content-Length included) with
  no body bytes.

## Configuration, environment & error handling (Phase 5)

**Dot-notation config** (`Sahil\Config\Repository`): `get/set/has/merge`
plus typed getters `getBool/getInt/getString/getList` — mismatches raise
`Sahil\Config\ConfigurationException` with the dotted key in the message
(never an HTTP error).

**Environment** (`Sahil\Support\Environment`): `.env` parsing supports
`KEY=VALUE`, full-line and trailing `#` comments, single-quoted raw
values, double-quoted values with `\"` and `\n` escapes, the literals
`true` / `false` / `null` / `empty`, and an optional `export` prefix.
**Unsupported:** `${OTHER}` interpolation, multi-line values, nested
quotes of any kind. **Precedence: operating-system environment variables
always beat `.env` values** (deployment runtime wins); values left unset
by the OS just fall through from `.env`. No `$_ENV`/`$_SERVER` mutation.

**Environment-specific configuration:** after `config/*.php` loads,
`config/{environment}.php` (e.g. `config/production.php`) is deep-merged
at the repository root when it exists (`['app' => [...], 'cache' => ...]`
section shape). Files named after a known environment
(development/testing/staging/production) are never treated as base
namespaces.

**Configuration validation at boot:** every violation is aggregated in
one `ConfigurationException` — enum checks for `app.environment`, type
checks for `app.debug`, validation of `app.timezone` and `app.log_level`,
and `app.debug=true` + production is rejected outright (diagnostics must
not be visible to clients in production).

**Logging** (`Sahil\Support\Logging\{LoggerContract,FileLogger}`): the
framework's own PSR-3-shaped contract (no extra dependencies).
`FileLogger` writes JSON lines (timestamp/level/message/context) under
`storage/logs/app.log` honoring the configured threshold
(`app.log_level`, default `info`). Context goes through the redactor, so
secret material can never reach the sink. A broken sink throws instead of
swallowing diagnostics.

**Secrets** (`Sahil\Support\Redactor`): dotted keys whose path touches
`password|passwd|secret|token|credential|api key|private|signing|encryption`
render as `[redacted]` — the whole subtree is marked and values are never
printed, by `config:show`, by loggers, or anywhere else. Real credentials
never belong inside the repository (see `.env.example` — copy to a local
`.env` which stays untracked).

**Centralized exception handling**
(`Sahil\Foundation\CentralExceptionHandler`): one `report()` (logs with
matching severity: `warning` for HTTP errors, `error` for real faults)
and one `render()` (`HttpException`s keep status + headers; anything else
renders `{"error":"server_error"}`; debug messages only when explicitly
enabled; stack traces and paths never reach clients in any mode). It is
installed on the kernel by `App\Providers\ObservabilityProvider`, which
is distinguishable from HTTP errors at the API level: configuration
failures occur before requests can dispatch.

**CLI** (the starter-kit's `bin/sahil`):
- `about` — application facts (name, environment, debug on/off, timezone, PHP),
- `config:show [namespace]` — secret-safe dotted configuration dump,
- `env:check` — documented variable names only (values are never
  printed); exits non-zero when a production deployment lacks required
  variables or runs with visible diagnostics,
- `--help` / `--version` — legacy options are retained.

## HTTP kernel & routing (Phase 4)

The kernel lives in `src/Http/Kernel.php`: one `handle(Request): Response`
call runs the full request lifecycle — router lookup, global and
route-level middleware, controller resolution through the DI container,
and exception→response rendering — inside a single request scope
(`scoped()` services resolve during the request and are dropped when the
scope ends, even on error paths).

**Routes** are declared in `routes/web.php` as an array of `Sahil\Http\Route`
objects, registered by `App\Providers\WebRoutesProvider`:

```php
Route::get('/users/{id}', [UserController::class, 'show'])->name('users.show');
Route::get('/posts/{slug?}', fn (Request $r) => Response::json([...]));
Route::post('/files/{path:\d+}', ...)->middleware(ThrottleMiddleware::class);
```

- Path syntax: static segments, `{param}` (one segment, URL-decoded),
  `{param:pattern}` (PCRE fragment, validated at registration) and a
  trailing optional `{param?}`.
- Unmatched paths raise `NotFoundHttpException` (404); a path match with
  a wrong verb raises `MethodNotAllowedHttpException` (405) with an
  `Allow` header.
- Handler conventions: handlers receive `(Request $request, array $params)`
  from the kernel — `$params` keys are the route parameter names (e.g.
  `/orders/{orderId}` → `$params['orderId']`), the Request is first. This
  contract is documented and covered by tests; bare positional guessing is
  never used.
- Handlers must return a `Sahil\Http\Response`. Controller classes are
  resolved through the container, so controllers can inject their own
  constructor dependencies (mill-ready services). Handler class/method
  existence is validated at route registration with clear errors.
- Named routes support reverse routing: `router->generate('users.show',
  ['id' => 42])`.

**Route groups.** `$router->group('/prefix', function (Router $g): void { ... },
middleware: [...])` — registers every collected route with `$prefix`
prepended and the group middleware inherited *before* route-level
middleware (group ≈ closer to the edge, route ≈ closer to the handler).
Nested bare-root routes (`/` inside a group) are rejected loudly.

**Middleware** implements
`Sahil\Http\Middleware\MiddlewareContract::handle(Request, Closure $next):
Response`. Global middleware is attached with `Kernel::using(...)`
(outermost first); per-route middleware with `Route::middleware()`;
group middleware with `Router::group(..., middleware: ...)`. Middleware
may short-circuit by returning a response without calling `$next()` — the
rest of the pipeline (and dispatch) is then skipped. Middleware executed
in registration order and unwinds in reverse order after the handler
returns (use `try/finally` where work must run on error paths).
Middleware can come as an instance, or as a plain class-string resolved
through the DI container.

**Exception handling.** Middleware and handler exceptions unwind through
the pipeline (finalize/`finally` code runs) and reach the kernel:
`Kernel::usingExceptionHandler(callable(Throwable): ?Response)` gets the
first chance; returning null (or not installing one) makes the kernel fall
back to built-in rendering — `HttpException`s keep status/headers
(message only in debug mode), any other throwable renders a plain
`{"error":"server_error"}` 500 — in debug mode the exception message is
added, but paths/stack traces never reach the client.

## Validation & form handling (Phase 6)

`Sahil\Validation\Validator` is a deterministic, rule-based validator:

```php
use Sahil\Validation\{Validator, Presets, FormInput, ValidationException};

$result = (new Validator([
    'email'        => 'required|email',
    'age'          => 'nullable|integer|min:0|max:130',
    'role'         => 'in:admin,editor',
    'code'         => 'regex:/^[A-Z]{3}$/',
    'user.name'    => 'required|string',
    'items.*.sku'  => 'required|string',
]))->validate($payload); // or validate(FormInput::fromRequest($request))

if ($result->passes()) {
    $clean = $result->data(); // VALIDATED data only — never raw input
} else {
    $errors = $result->errors(); // ['field' => ['message', ...]]
}
```

Rule catalog (string descriptors, `|`-separated): `required`, `nullable`,
`string`, `integer`, `numeric`, `boolean`, `email`, `url`, `array`,
`min:N`, `max:N`, `length:min,max` (mb-aware strings and array counts),
`in:a,b`, `notIn:a,b`, `regex:/.../` (delimited PCRE, `e` modifier
forbidden), `allowsControlChars` (opt-out of control-character
rejection). Custom rules are `RuleContract` implementations or closures
`fn(string $field, mixed $value): ?string`. Custom message keys:
`field.rule` → `field` → bare `rule`.

Contract highlights:

- **Raw vs validated is explicit.** Fields without rules never appear in
  `result->data()`; failed fields validate to nothing. There is no
  accessor that mixes raw input with validated data.
- **No silent coercion.** The only casts are documented and lossless:
  `integer` accepts ints or plain digit strings (re-encoding must be
  identical), `numeric` accepts numeric text (→ int when lossless,
  else float), `boolean` accepts real bools or exactly `"true"` /
  `"false"`. `"1"`, `"007"`, `1.0` under `integer` all fail.
- **Malicious input defaults to rejection:** strings containing NUL
  bytes, C0 controls (besides `\t\n\r`), DEL or C1 codepoints fail the
  `string` rule. HTML/script payloads are NOT sanitized — they pass
  through byte-for-byte; output encoding remains the application's job.
- **Semantics:** `required` fails for missing/null/`""`; without
  `required`, missing or null fields pass silently and validate to
  nothing; a field's chain stops at its first failure; other fields keep
  validating. Empty strings are present values (the `numeric` rule
  rejects `""`).
- **Nested data & wildcards:** `user.name` reaches `$data['user']['name']`;
  `items.*.sku` expands onto every element (missing/wrong parents
  expand to nothing).
- **Opt-in normalization only:** `->normalize('trim')` (the sole
  operation today) trims strings before checks; by default raw input is
  judged as-is.
- **Reusable definitions:** `Presets::define('name', [...rules])` once,
  then `Presets::validator('name', messages: [...])` anywhere; presets
  are copied on read so callers cannot mutate them.
- **Result types:** `ValidationResult` (immutable: `passes()`, `fails()`,
  `errors()`, `errorLines()`, `data()`) and, for exception-driven
  controllers, `ValidationException` via `result->toException()` (the
  message lists `field: message` pairs).
- **Request payloads:** `FormInput::fromRequest($request)` merges query
  parameters and the parsed body (JSON tree or form fields; body wins
  collisions; malformed JSON keeps the request object's
  `BadRequestHttpException`). The payload is RAW input — only
  `result->data()` may be trusted downstream.

## Database layer (Phase 7)

Driver-based DSL over **PDO**. Supported drivers (each gated by real
integration tests — see "Database setup"): **MySQL/MariaDB** (`pdo_mysql`)
and **PostgreSQL** (`pdo_pgsql`). Login credentials never appear in log
or error output; query errors carry the SQL text and driver message but
not the bound values ({@see QueryException::parameters()} exposes them
only via explicit calls).

Configuration lives in `config/database.php` and is driven entirely by
environment variables (documented in `.env.example`); real credentials
never belong in the file or in source control:

```bash
DB_DRIVER=mysql          # or pgsql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=sahil
DB_USERNAME=app
DB_PASSWORD=             # never inline; `.env` is git-ignored
DB_CHARSET=utf8mb4
```

`App\Providers\DatabaseProvider` binds `ConnectionFactory`, `Connection`,
`Schema` and the migration/seed services into the container. Every one of
them is LAZY: nothing connects at boot, so `bin/sahil about` and requests
that never touch the database keep working when the server is down. The
connection is opened the first time it is actually resolved, and a failure
surfaces as `ConnectionException` (message never contains the password).

Usage:

```php
use Sahil\Database\{ConnectionFactory, Connection, Expression};

$factory = new ConnectionFactory();
$db = $factory->make($configuration);        // may throw ConnectionException

$rows = $db->table('users')->select('id', 'name')
    ->where('status', '=', 'active')          // value → bound parameter
    ->whereIn('role', ['admin', 'support'])   // each element bound
    ->whereNull('deleted_at')
    ->join('profiles', 'profiles.user_id', 'users.id', 'left')
    ->orderBy('users.created_at', 'desc')
    ->limit(20)->offset(40)
    ->get();                                  // QueryResult (rows(), first(), affected(), lastInsertId())

$db->table('users')->insert(['name' => 'Ada', 'score' => 5]);
$db->table('users')->where('id', '=', $id)->update(['score' => 9]);
$db->table('users')->where('id', '=', $id)->delete();
```

Safety contract:

- **User-controlled values NEVER appear in SQL text** — all values are
  PDO-bound for reads AND writes (`INSERT … VALUES (?, ?)` etc.).
- **Identifiers** (tables, columns, aliases) must match
  `/^[A-Za-z_][A-Za-z0-9_]{0,62}$/` per dot-segment (≤2 segments); they
  are validated and then quoted per dialect (`` `a`.`b` `` / `"a"."b"`).
  Unknown table/alias prefixes in `where()/orderBy()` are refused.
  `toSql()` only ever shows placeholders.
- **Raw SQL is opt-in** via the explicit `Expression` object — the only
  escape hatch, visible at the call site; `whereRaw(expression, binds)`
  keeps even its values bound.
- **UPDATE/DELETE without WHERE refuse to run**; array values are
  refused (no implicit expansion); evil/false operators and directions
  fail loudly (`InvalidArgumentException`).

Transactions & lifecycle:

```php
$db->transaction(function (Connection $db): void {
    $db->table('users')->insert([...]);
    $db->table('audit')->insert([...]);      // one unit
});                                          // commit on success

// on ANY throwable the unit is rolled back and the ORIGINAL exception
// propagates unchanged; nested calls become SAVEPOINT variants and the
// innermost failure rolls back just to its savepoint
```

Worker rules (repeat: connections die on idle timeouts): long-running
workers should call `ping()` at the start of each job and
`reconnect($driverOptions)` when it returns false; `disconnect()`
rolls back any still-open transaction and frees the handle. Transactions
must not span jobs.

### Database setup (integration tests)

The integration suite (`tests/Database/DatabaseIntegrationTest.php`)
runs REAL create/insert/read/update/rollback/drop cycles whenever
credentials are provided via OS env — otherwise those tests **skip**:

```bash
export SAHIL_DB_TEST_MYSQL='mysql://user:password@127.0.0.1:3306/sahil_tests'
export SAHIL_DB_TEST_PGSQL='pgsql://user:password@127.0.0.1:5432/sahil_tests'
vendor/bin/phpunit tests/Database/DatabaseIntegrationTest.php
```

Create the test DSNs and users (with a dedicated low-privilege
accounting schema — never reuse production credentials):

```sql
CREATE DATABASE sahil_tests;
CREATE USER 'sahil_test'@'%' IDENTIFIED BY '…';
GRANT ALL ON sahil_tests.* TO 'sahil_test'@'%';
-- pgsql:
CREATE USER sahil_test WITH PASSWORD '…'; CREATE DATABASE sahil_tests; GRANT ALL ON DATABASE sahil_tests TO sahil_test;
```

Convenience: `scripts/test-databases.sh` boots disposable local test
instances (documented URLs below) when you have no server of your own:

```bash
./scripts/test-databases.sh
SAHIL_DB_TEST_MYSQL='mysql://root@127.0.0.1:33306/sahil_tests' \
SAHIL_DB_TEST_PGSQL='pgsql://jinx@127.0.0.1:55432/sahil_tests' \
vendor/bin/phpunit
```

## ORM & data models (Phase 8)

Active-record models on top of the Phase-7 layer. **Every value is
still bound through the query builder** — the ORM adds no string
interpolation; the only raw-SQL channel (`Expression` / explicit
`Connection::execute`) is unchanged and unused internally except for
the pivot SELECT, which quotes identifiers through the driver.

### Definition

```php
use Sahil\Orm\Model;
use Sahil\Orm\Relation;

final class User extends Model
{
    protected ?string $table = 'users';       // default: snake class + 's'
    protected string $primaryKey = 'id';
    protected array $fillable = ['name', 'email'];   // mass-assignment allowlist
    protected array $guarded = ['*'];                  // refused keys (** default: everything not fillable)
    protected array $hidden = ['password'];           // never serialized
    protected array $casts = ['active' => 'bool'];    // int|float|bool|string
    public bool $timestamps = true;                   // created_at / updated_at maintained

    public function profile(): Relation { return $this->hasOne(Profile::class); }
    public function posts():  Relation { return $this->hasMany(Post::class); }
    public function roles():  Relation { return $this->belongsToMany(Role::class); }
    // belongsTo(Order::class) — this row holds `order_id`
}
```

### Lifecycle

```php
$user = User::create(['name' => 'Ada']);  // fill (guarded) → INSERT → pk backfilled
$found = User::find($user->id);           // null when absent (findOrFail-less by design)
$found->name = 'Grace';                   // attribute writes tracked
$found->isDirty('name');  $found->dirty();  // 'name'
$found->save();                            // UPDATE *only dirty columns* + updated_at
$found->refresh();                         // re-read row, discard unsaved edits
$found->delete();                          // DELETE by pk (throws if pk unset)
```

Mass assignment is **loud and atomic**: `fill(['is_admin' => 1])`
throws `MassAssignmentException` naming every rejected key and leaves
attributes untouched. Never rely on silent drops.

### Relations & eager loading

```php
// Lazy (one query per access — fine for a single row):
$profile = $user->profile;             // property magic → $user->load('profile')
$roles = $user->load('roles');         // OrmResult

// Eager (exactly ONE extra query per dotted level — prevents N+1):
$users = User::query()->with('profile', 'posts')->get();
$posts = Post::query()->with('author')->get();          // nested: belongsTo on posts
$users = User::query()->with('roles')->get();           // 2 queries (pivot + related)

// Serializing includes loaded relations; each model applies its own $hidden.
json_encode($users);      // hidden fields never appear, even nested
```

### Queries, scopes, pagination

```php
User::query()
    ->where('active', '=', 1)
    ->orderBy('id', 'desc')
    ->limit(10)->offset(0)
    ->get();                                  // OrmResult<User>

Post::query()->published(2026)->get();        // scope: scopePublished(ModelQuery $q, ...) on the model
User::query()->paginate(15);                  // PageResult: result, currentPage, perPage, total, lastPage()
                                              // → toArray()/json: {"data": [...], "meta": {...}} (2 queries total)
```

### Does and not

- Connection wiring is a single static decision: `Model::useConnection($db)`
  (a provider/test calls it once); unset → loud error, never a null dereference.
- `$casts` are explicit per-attribute (`int|float|bool|string`) — no
  silent coercion, no date parsing magic.
- Timestamps write SQL `DATETIME` textual form (`Y-m-d H:i:s`).
- The PK backfill via `lastInsertId()` works on MySQL; on PostgreSQL the
  Phase-7 limitation applies (returning clauses not implemented) — create
  rows with explicit ids or fetch by unique keys.

**Documented limits:** no polymorphic relations, no soft deletes, no
aggregate helpers beyond pagination count, no bulk inserts, no lazy-loading
concurrency guard (loops using lazy properties DO issue one
query per row — use `with()`), no attribute-level uniqueness. Unknown
scopes, relations or misplaced relation names fail loudly

### Tests

- `tests/Orm/ModelTest.php` — mass assignment/atomicity, guards,
  casts, dirty tracking, timestamps, hidden serialization, descriptor
  naming, eager-load query counting (N+1 regression), lazy magic.
- `tests/Orm/ModelIntegrationTest.php` — REAL-database lifecycle +
  all four relation types + nested eager + pagination + scopes, gated
  on `SAHIL_DB_TEST_MYSQL` / `SAHIL_DB_TEST_PGSQL` (skip when absent).

## Migrations & seeding (Phase 9)

`Sahil\Migrations\*` (ledger, discovery, execution, locking, seeding) and
`Sahil\Database\Schema\*` (the driver-agnostic schema builder).

```bash
bin/sahil migrate                    # apply every pending migration
bin/sahil migrate:status             # applied / pending / missing
bin/sahil migrate:rollback [--steps=N]
bin/sahil db:seed [--class=UsersSeeder]
```

Each of `migrate`, `migrate:rollback` and `db:seed` also takes `--force`
(see "Destructive operations" below).

### Layout & definition

`database/migrations/` and `database/seeders/` each hold plain PHP files
that RETURN an instance. The file name is the migration name, and the
`YYYY_MM_DD_HHMMSS_` prefix makes file order chronological — which is also
file order of execution.

```php
// database/migrations/2026_01_01_000000_create_users_table.php
return new class extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('users', function (Table $table): void {
            $table->id();
            $table->string('email', 190)->unique();
            $table->boolean('active')->default(true);
            $table->foreignId('team_id')->nullable();
            $table->timestamps();
            $table->index(['email', 'active']);
            $table->foreign('team_id')->references('id')->on('teams')->onDelete('set null');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('users');
    }
};
```

**Reversibility is derived, never declared**: a migration is reversible
exactly when it overrides `down()`. Forward-only migrations cannot be
rolled back by accident — the migrator refuses the batch and names them.

### Schema builder

The same migration body compiles to backtick MySQL DDL and double-quoted
PostgreSQL DDL.

| Area | API |
|------|-----|
| Tables | `create()`, `createIfNotExists()`, `table()` (alter), `drop()`, `dropIfExists()`, `hasTable()` |
| Columns | `id()`, `foreignId()`, `bigInteger()`, `integer()`, `string(len)`, `text()`, `boolean()`, `timestamp()`, `dateTime()`, `decimal(p,s)`, `float()`, `json()`, `timestamps()` |
| Column modifiers | `nullable()`, `default(v)`, `defaultExpression(sql)`, `unique()`, `autoIncrement()`, `unsigned()` |
| Indexes | `index()`, `unique()`, `primary()` — single column or list, optional explicit name |
| Foreign keys | `foreign(col)->references(col)->on(table)->onDelete(a)->onUpdate(a)` |
| Alter-only | `dropColumn()`, `dropIndex()`, `dropForeign()` |

Guarantees and driver differences:

- **Identifiers are validated, then quoted per dialect.** Only
  `/^[A-Za-z_][A-Za-z0-9_]{0,62}$/` per dot-segment reaches DDL, so a
  migration can never interpolate arbitrary SQL through a table, column,
  index or constraint name. Derived constraint names are deterministic
  (`users_email_unique`) and length-capped to what the validator accepts.
- **`foreignId()` must pair with `id()`.** MySQL requires a foreign key
  column to have the *identical* type to the column it references, and
  `id()` is `BIGINT UNSIGNED` there; `bigInteger()` is signed and would be
  rejected. `foreignId()` renders the matching type per dialect.
- **Literals are escaped by the grammar** (DDL cannot take bound
  parameters): MySQL escapes both `\` and `'`, PostgreSQL doubles `'`,
  booleans render `1`/`0` vs `TRUE`/`FALSE`, and a NUL byte is refused.
  Only scalars are accepted; raw SQL needs an explicit
  `defaultExpression()`.
- **Alter statements are ordered for safety**: foreign keys are dropped,
  then indexes, then columns, before anything is added. Dropping an index
  after its column, or dropping a column still referenced by a foreign
  key, fails on both drivers — and on MySQL that failure cannot be undone.
- **Where a driver genuinely differs, the difference is explicit**:
  PostgreSQL cannot declare a plain index inside `CREATE TABLE`, so those
  become `CREATE INDEX` statements; PostgreSQL has no unsigned integer
  types, so `unsigned()` is REFUSED there instead of silently dropped;
  `id()`/`json()` map to `BIGSERIAL`/`JSONB`.
- **Not implemented (write raw DDL in a migration if you need it):**
  column rename/modify, index/constraint renaming, and schema diffing.
  `dropColumn()`/`dropIndex()`/`dropForeign()` are the only destructive
  table changes the builder expresses.

### Ledger, repeatability and failure behaviour

Applied migrations are recorded in a `sahil_migrations` table
(`migration` unique, `batch`, `applied_at`), created on demand with
`createIfNotExists` on the first `migrate`. `migrate:status` is read-only:
on a fresh database it reports everything as pending and creates nothing.

| Property | Behaviour |
|----------|-----------|
| Fresh installation | First `migrate` creates the ledger and applies everything as batch 1 |
| Repeat execution | Already-applied migrations are never re-run; a second `migrate` writes nothing |
| Recording | A migration is recorded only AFTER `up()` succeeded — a failed one stays pending and is retried |
| Failure reporting | `MigrationException` names the migration and keeps the driver's message |
| Transactional DDL | PostgreSQL runs each migration in a transaction, so a failure rolls the DDL back completely (`pgsql` reported as such). MySQL/MariaDB cause an implicit COMMIT on DDL, so the report says the schema may be partially changed — the framework never pretends a rollback happened |
| Rollback order | Newest migration first, within the newest `--steps=N` batches |

### Concurrency (locking)

Every migration run holds a **database advisory lock** (`GET_LOCK` on
MySQL, `pg_try_advisory_lock` on PostgreSQL) for its whole duration, so two
processes cannot migrate at the same time. A second process WAITS up to
`database.lock_timeout` seconds (default 10) and then fails with a clear
message naming the lock.

```
migrate: Another process is running migrations (advisory lock
[sahil_migrations:sahil] is held). Wait for it to finish and retry.
```

Because the lock lives in the database session rather than in a file or a
ledger row, a crashed process cannot leave it stuck — the server releases
it when the session ends. Scope differs per server and is handled: MySQL's
`GET_LOCK` namespace is **global to the server**, so the lock name includes
the database name to keep databases independent, while PostgreSQL advisory
locks are already **per database** (both verified against real servers).

### Destructive operations

Rollback, seeding and any production schema change require deliberate
operator intent. The policy lives in one place (`MutationGuard`) and is the
same for every command:

1. **in `production`, an interactive "yes" is not enough** — `--force` is
   required, so a production change is always an explicit, recorded act;
2. **everywhere else**, `--force` authorises the operation, otherwise the
   operator is prompted;
3. **an unattended run never confirms by accident** — when STDIN is not a
   terminal the confirmation answers `no` without prompting, so cron/CI
   must pass `--force` deliberately;
4. a refusal happens **before** the database is touched.

Two further gates specifically for rollback:

- **Forward-only migrations block the batch.** With `--force` they are
  SKIPPED and reported (`left applied`); without it the rollback is refused
  and they are named.
- **Orphaned ledger records block it too.** If an applied migration has no
  file on disk, rollback stops and tells you; `--force` forgets those
  records deliberately rather than guessing. Note that a forgotten record
  can leave a table behind, and the dependent-object error that follows is
  reported rather than worked around with a `DROP ... CASCADE`.

### Seeding

```php
// database/seeders/UsersSeeder.php
return new class extends Seeder {
    public function run(SeedContext $context): void
    {
        // Values still go through the parameterized query builder.
        $context->connection->table('users')->insert(['name' => 'Ada']);
    }
};
```

- Seeders are registered under their file name and run in name order;
  `db:seed --class=UsersSeeder` runs exactly one.
- Each seeder runs **at most once per execution**, so a composite seeder
  calling `$context->call('OtherSeeder')` — and a cycle — terminates. The
  report lists everything that actually executed, in start order.
- Each seeder runs in a **transaction**, so a failure leaves no
  half-inserted rows; the failure names the seeder and keeps the driver's
  message.
- `db:seed` is treated as destructive (it may truncate or replace data), so
  it goes through the same gate as rollback. Keep seeders idempotent:
  there is deliberately no "already seeded" state.

### Tests

- `tests/Migrations/SchemaGrammarTest.php` — DDL compilation per dialect:
  quoting, escaping, index placement, alter ordering, refused features.
- `tests/Migrations/MigrationLoaderTest.php` — discovery, ordering,
  malformed files, derived reversibility.
- `tests/Migrations/MigrationLockTest.php` — lock statements,
  per-driver acquired-value normalisation, guaranteed release.
- `tests/Migrations/MutationGuardTest.php` — the destructive-operation
  policy per environment.
- `tests/Migrations/SeederTest.php` — registration, once-only execution,
  composite calls, transactional failure.
- `tests/Migrations/MigrationIntegrationTest.php` — REAL databases: fresh
  install, repeat run, rollback, failed migration (including the
  transactional-DDL difference per driver), concurrent-run locking,
  destructive gate, forward-only skip, orphaned records, seeding.
  Gated on `SAHIL_DB_TEST_MYSQL` / `SAHIL_DB_TEST_PGSQL` (skip when absent).

## Backup & production migration procedure

Migrations run DDL: **assume they can fail halfway on MySQL/MariaDB and
that a forward-only migration cannot be reversed at all.** The procedure
below is the one the safety gates are designed around.

1. **Back up first, and prove the backup is readable.** Always back up the
   `sahil_migrations` ledger with the schema, or you lose the ability to
   reconcile what is applied.

   ```bash
   # MySQL / MariaDB (consistent snapshot without locking writers)
   mysqldump --single-transaction --routines --triggers sahil \
     > sahil-$(date +%F-%H%M%S).sql

   # PostgreSQL
   pg_dump --format=custom sahil > sahil-$(date +%F-%H%M%S).dump
   ```

   Restore (rehearse this against a scratch database before you need it):

   ```bash
   mysql sahil < sahil-2026-10-27-120000.sql
   pg_restore --clean --if-exists -d sahil sahil-2026-10-27-120000.dump
   ```

2. **Inspect before you apply.** `bin/sahil migrate:status` lists applied,
   pending and missing migrations. Read the pending migrations for
   `dropColumn()`/`dropIndex()`/`dropForeign()` and for anything that
   rewrites data.

3. **Prefer reversible migrations.** A `down()` method is what makes a
   rollback possible; a forward-only migration makes the backup the only
   way back.

4. **Apply as an explicit deploy step, with `--force` in production.**
   Never run migrations from a web request or from every application
   instance at boot. One runner does it:

   ```bash
   bin/sahil migrate --force     # required in production
   ```

   Concurrent attempts are serialized by the advisory lock, but the lock is
   a safety net for a race, not a substitute for a gated deploy step.

5. **Verify, then keep the backup** until the next release has been running
   cleanly.

6. **If a migration fails:**
   - PostgreSQL: the failing migration's DDL was rolled back; fix the
     migration and re-run (it is still pending).
   - MySQL/MariaDB: the schema may be **partially changed** — the report
     says so explicitly. Inspect the schema, undo what landed by hand, then
     re-run. A `down()` on a half-applied migration will often fail, and the
     error is reported rather than hidden.
   - The failed migration is never recorded, so nothing silently claims to
     be applied.

7. **Rollback when you need it:**

   ```bash
   bin/sahil migrate:rollback --force            # newest batch
   bin/sahil migrate:rollback --steps=2 --force  # newest two batches
   ```

   Restore from backup instead when the batch contains forward-only
   migrations or when DDL landed partially.

### Environment variables

| Variable | Purpose |
|----------|---------|
| `DB_DRIVER` | `mysql` or `pgsql` |
| `DB_HOST`, `DB_PORT` | server endpoint (`3306` / `5432` defaults) |
| `DB_DATABASE` | schema name |
| `DB_USERNAME`, `DB_PASSWORD` | credentials — never committed, never logged |
| `DB_CHARSET` | MySQL connection charset (default `utf8mb4`) |

`config:show database` prints every key with `password` as `[redacted]`.

## Queued jobs

Work that should not run inside a request is queued and executed by a
worker process. The queue is **database-backed**: jobs live in the `jobs`
table, so they survive a restart and can be inspected with plain SQL. The
shipped migrations create `jobs` and `failed_jobs` (`bin/sahil migrate`).

### Defining and dispatching a job

```php
// app/Jobs/SendWelcomeEmail.php
final class SendWelcomeEmail extends Job
{
    public function __construct(private readonly string $email)
    {
    }

    public function handle(): void
    {
        // send the welcome email
    }

    public function maxAttempts(): ?int
    {
        return 5;                      // optional: overrides queue.retry.max_attempts
    }

    public function retryDelay(int $attempt): ?int
    {
        return 2 ** $attempt;          // optional: 2s, 4s, 8s, …
    }
}

(new SendWelcomeEmail('ada@example.com'))
    ->onQueue('emails')                // optional: defaults to the worker's queue
    ->dispatch();                      // returns the job id
```

A job is serialized whole (native `serialize`), so it may only hold plain
data — no closures or resources. `maxAttempts()` / `retryDelay()` return
`null` to defer to the configured worker defaults.

### Running the worker

```bash
bin/sahil queue:work                    # process until interrupted
bin/sahil queue:work --once             # process at most one job
bin/sahil queue:work --queue=emails --max=100
```

The worker reserves one job at a time in a short transaction: the oldest
ready row is locked, `attempts` is incremented and the row is marked
reserved, so two workers can never take the same job. Timestamps are stored
as `Y-m-d H:i:s`, the same representation the ORM uses, which keeps the SQL
portable between MySQL and PostgreSQL.

| Situation | Behaviour |
|-----------|-----------|
| Handler returns | The job is acknowledged and deleted |
| Handler throws, attempts remain | The job is released and available again after its retry delay |
| Handler throws, attempts exhausted | The payload and message are written to `failed_jobs`, the job is deleted |
| Corrupt payload | Recorded as failed (never retried forever) and deleted |
| Delayed dispatch (`dispatch(30)`) | The row is invisible to workers until it is due |

Attempts are counted at reservation time, so a worker that crashes
mid-job still consumes an attempt rather than retrying forever.

### Failed jobs

```bash
bin/sahil queue:failed                 # id, queue, time, reason
bin/sahil queue:retry <id>             # replay one failed job
bin/sahil queue:retry --all            # replay every failure on the queue
bin/sahil queue:flush                  # forget the queue's failures
```

A retried job is pushed back through the normal dispatcher, so it is an
ordinary job again; the failed record is removed only after the push
succeeds. All four commands fail with one clear line when the database is
unreachable instead of a stack trace.

### Environment variables

| Variable | Purpose |
|----------|---------|
| `QUEUE_CONNECTION` | Queue connection from `config/queue.php` (default `database`) |
| `QUEUE_NAME` | Queue the worker consumes (default `default`) |
| `QUEUE_SLEEP` | Seconds between polls when the queue is empty (default `1`) |
| `QUEUE_MAX_ATTEMPTS` | Default attempt budget per job (default `3`) |
| `QUEUE_RETRY_DELAY` | Default seconds before a retry (default `0`) |
| `QUEUE_TABLE`, `FAILED_JOB_TABLE` | Table names |

### Tests

- `tests/Queue/DatabaseQueueTest.php` — SQL and parameter binding, atomic
  reservation, attempt counting, delay and release.
- `tests/Queue/WorkerTest.php` — acknowledgement, retries until the budget
  is spent, per-job retry policy, corrupt payloads, bounded `work()`.
- `tests/Queue/QueueManagerTest.php` — serialization, queue selection,
  configuration errors, reused store.
- `tests/Queue/JobTest.php` — job defaults and per-job overrides.

## File storage & uploads

Files are addressed through **named disks**. A disk has a driver, a root and
whether it is publicly served; unqualified calls use `storage.default`.

| Disk | Driver | Root | Public | Permissions |
|------|--------|------|--------|-------------|
| `local` | `local` | `STORAGE_PRIVATE_PATH` (`storage/uploads/private`) | no | `0600` files / `0700` dirs |
| `public` | `local` | `STORAGE_PUBLIC_PATH` (`public/storage`) | yes | `0644` files / `0755` dirs |

Two layout rules are enforced when a disk is resolved:

- a **private** disk whose root resolves inside the public document root, or
  inside another disk marked `public`, is refused with a `StorageException`
  — private files can never become web-accessible through configuration;
- a private disk never returns a URL: `StorageContract::url()` is `null` for
  it, and nothing under `storage/uploads/` is routed by the framework.

### Reading, writing and cleanup

```php
use Sahil\Contracts\Storage\StorageContract;

$storage = app()->container()->make(StorageContract::class);

$storage->put('invoices/2026/001.pdf', $bytes);      // string or stream
$storage->get('invoices/2026/001.pdf');              // string
$storage->readStream('invoices/2026/001.pdf');       // resource
$storage->has(...); $storage->size(...); $storage->mimeType(...);
$storage->lastModified(...);
$storage->copy('a.pdf', 'archive/a.pdf');
$storage->move('tmp/a.pdf', 'a.pdf');
$storage->makeDirectory('exports/2026', true);
$storage->deleteDirectory('exports/2026');
$storage->delete('invoices/2026/001.pdf');
$storage->listContents('invoices');                  // files + dirs, sorted
$storage->url('avatars/1.png', 'public');            // null on private disks
```

Every path is relative to the disk root. Absolute paths, `.`/`..` segments,
NUL bytes, control characters, empty segments and segments over 255 bytes
are rejected before any SQL or filesystem call; symbolic links are refused
(never followed) for read, write, copy, move and recursive delete, and are
skipped when listing. Files are written without execute permission.

**Cleanup** is explicit — the framework never deletes files on its own:

```php
foreach ($storage->listContents('avatars/2025') as $entry) {
    $expired = $entry['type'] === 'file'
        && ($entry['last_modified'] ?? 0) < strtotime('-90 days');

    if ($expired) {
        $storage->delete($entry['path']);
    }
}

$storage->deleteDirectory('exports/2026');   // removes files and the directory
```

Empty parent directories are left in place; remove them with
`deleteDirectory()`. PHP removes its own temporary upload files, and a failed
upload verification deletes the partially stored file before raising.

### Uploads

```php
$file = $storage->upload('avatar', [
    'max_size' => 2 * 1024 * 1024,
    'allowed_mimes' => ['image/jpeg', 'image/png'],
    'path' => 'avatars/2026',
], 'public');

$file->path;          // avatars/2026/<32 hex>.png   (relative to the disk)
$file->storedName;    // <32 hex>.png
$file->originalName;  // sanitized client name, metadata only
$file->size; $file->mimeType; $file->url; $file->disk;
```

Client input is never trusted:

| Rule | Behaviour |
|------|-----------|
| Type | Detected from content with `finfo`; the client-declared MIME type is ignored |
| Extension | Derived from the detected type, never from the client file name |
| Name | Random 32 hex characters; the client name is only kept sanitized as `originalName` |
| Size | Measured from the upload stream, cross-checked with the declared size, and compared to `max_size` |
| Errors | Partial, empty, missing and oversized uploads raise `UploadException` (HTTP 400) |
| Executables | `php`, `phtml`, `phar`, `cgi`, `sh`, … are always refused — no option enables them |
| Verification | After writing, type and size are re-read **through the driver**; a mismatch deletes the file and raises |

`allowed_mimes` defaults to `STORAGE_ALLOWED_MIMES`; `max_size` defaults to
`STORAGE_MAX_UPLOAD_SIZE`.

### Remote adapters

A backend only has to implement `StorageDriverContract` (`put`, `get`,
`readStream`, `has`, `size`, `mimeType`, `delete`, `copy`, `move`, directory
methods, `listContents`, `url`). Register its factory and point a disk at it:

```php
// config/queue-like disk entry: ['driver' => 's3', 'bucket' => '...']
$manager->extend('s3', static fn (array $disk): StorageDriverContract => new S3Driver(
    bucket: $disk['bucket'],
));
```

Uploads go through the driver only (no direct `filesize()`/`unlink()` on a
local path), so the same validation and verification run for remote stores.

### Web server hardening (required)

Public storage is static content only. `public/storage/.htaccess` disables
PHP/CGI handlers and sends `X-Content-Type-Options: nosniff`; for nginx:

```nginx
location ^~ /storage/ {
    autoindex off;
    add_header X-Content-Type-Options nosniff;
    location ~ \.(php[0-9]?|phtml|phar|cgi|pl|py|rb|sh)$ { deny all; }
}
```

Never point `STORAGE_PRIVATE_PATH` inside the document root, and never create
a symlink from `public/` into `storage/uploads/` — the framework refuses the
first, and the driver refuses to follow symlinks internally, but the web
server is still part of the trust boundary.

### Environment variables

| Variable | Purpose |
|----------|---------|
| `STORAGE_DISK` | Default disk (`local` or `public`) |
| `STORAGE_PRIVATE_PATH` | Private disk root (default `storage/uploads/private`) |
| `STORAGE_PUBLIC_PATH` | Public disk root (default `public/storage`) |
| `STORAGE_PUBLIC_URL` | URL prefix for the public disk (default `APP_URL/storage`) |
| `STORAGE_MAX_UPLOAD_SIZE` | Maximum upload size in bytes (default `10485760`) |
| `STORAGE_ALLOWED_MIMES` | Comma-separated permitted types, detected server-side |

### Tests

- `tests/Storage/LocalDriverTest.php` — round trips, directory handling,
  MIME detection, permissions, traversal/NUL/control-character rejection,
  symlink refusal, URL safety.
- `tests/Storage/StorageManagerTest.php` — disk resolution and caching,
  default disk delegation, custom driver factories, private/public layout
  enforcement.
- `tests/Storage/UploadSecurityTest.php` — malformed and oversized uploads,
  forged MIME types and extensions, executable-extension refusal, name
  sanitization, client path traversal, remote-adapter verification.

## Task scheduler

Recurring work is declared as PHP tasks in `routes/schedule.php` and run by
one command that an external trigger (cron or a systemd timer) invokes every
minute. Tasks are plain callables: no queue, no worker process of their own.

```php
use Sahil\Scheduler\Schedule;

return static function (Schedule $schedule): void {
    $schedule->call('prune-exports', static function (): void {
        // delete files, send reports, warm caches, ...
    })
        ->dailyAt('03:30')
        ->inTimezone('Europe/Berlin')
        ->withoutOverlapping()
        ->timeoutAfter(300)
        ->describe('Delete exports older than a day.');
};
```

The task name is its identity: it must be unique and is what the overlap
lock and `--only=NAME` refer to. An invalid name, a malformed expression or
a duplicate registration is a configuration error, not a silent skip.

| Schedule | Cron equivalent |
|----------|-----------------|
| `everyMinute()` | `* * * * *` |
| `every(5)` | `*/5 * * * *` |
| `hourly()`, `hourlyAt(15)` | `0 * * * *`, `15 * * * *` |
| `daily()`, `dailyAt('03:30')` | `0 0 * * *`, `30 3 * * *` |
| `weekly()`, `weeklyOn(1, '09:00')` | `0 0 * * 0`, `0 9 * * 1` |
| `monthly()`, `monthlyOn(15, '12:00')` | `0 0 1 * *`, `0 12 15 * *` |
| `cron('*/10 8-18 * * 1-5')` | any five-field expression |

The expression syntax supports `*`, values, ranges, lists, steps and month /
weekday names (`JAN`, `MON`, …); both day fields follow the standard cron
rule (if both are restricted, either may match).

### Timezones and daylight saving

Matching is done on the **local wall clock** of the task's timezone, so
`dailyAt('09:00')->inTimezone('America/New_York')` runs at 14:00 UTC in
January and 13:00 UTC in July. Across a transition:

| Transition | Behaviour |
|------------|-----------|
| Clocks move forward (02:00 → 03:00) | A local time that does not exist (02:30) simply does not run that day |
| Clocks move back (02:00 → 01:00) | A local time that occurs twice (01:30) matches both occurrences |

The default timezone is `SCHEDULE_TIMEZONE`, falling back to `APP_TIMEZONE`.

### Missed runs

- Seconds are ignored: a trigger that fires at `09:00:47` still runs the
  `09:00` task.
- A minute that passed while the runner was not invoked is **not**
  back-filled — starting at `09:01` does nothing for the `09:00` task
  (standard cron semantics). `schedule:list` shows the next run instead.
- The external trigger therefore has to fire at least once per minute; a
  long-running task delays the same invocation, not later ones.

### Overlap prevention

`withoutOverlapping()` makes a due task skip itself while a previous run is
still in progress. The lock is taken without waiting and is always released
in a `finally` block, including after a failure or timeout.

| `SCHEDULE_LOCK` | Backend | Scope |
|-----------------|---------|-------|
| `file` (default) | `flock()` in `storage/framework/schedule` | one host; a crashed process releases its lock automatically |
| `database` | advisory locks (`GET_LOCK` / `pg_try_advisory_lock`) | every host sharing the database |

Use `database` whenever more than one machine can run the scheduler.

### Timeouts and failure reporting

- `timeoutAfter(300)` marks a task that runs longer than 300 seconds as
  failed. On a CLI process with `pcntl` the task is really interrupted with
  SIGALRM; without signal support PHP cannot stop synchronous code, so the
  overrun is measured and reported the moment the task returns. `0` (the
  default) disables the timeout.
- A throwing task is recorded as failed, logged at `error` with the task
  name, expression, timezone and exception class, and the remaining due
  tasks still run.
- `schedule:run` exits `1` when anything failed (so cron mail / systemd
  marks the unit failed) and `0` otherwise.

### Commands

```bash
bin/sahil schedule:list                          # cadence, timezone, lock, next run
bin/sahil schedule:list --at="2026-03-08 03:30"  # inspect a specific instant
bin/sahil schedule:run                           # the cron entry point
bin/sahil schedule:run --only=prune-exports      # one task (still must be due)
bin/sahil schedule:run --at="2026-10-28 09:00"   # deterministic evaluation
```

`--at` is interpreted in the schedule timezone and affects only the
scheduler; it is how tests and operators reproduce a minute exactly.

### Cron / systemd integration

Cron (one line, every minute, same user that owns `storage/`):

```cron
* * * * * cd /var/www/sahil && /usr/bin/php bin/sahil schedule:run >> /var/log/sahil-schedule.log 2>&1
```

systemd timer (preferred on servers: no minute-alignment drift, structured
logs, no mail):

```ini
# /etc/systemd/system/sahil-schedule.service
[Unit]
Description=SahilPHP task scheduler

[Service]
Type=oneshot
WorkingDirectory=/var/www/sahil
ExecStart=/usr/bin/php bin/sahil schedule:run
User=www-data
```

```ini
# /etc/systemd/system/sahil-schedule.timer
[Unit]
Description=Run SahilPHP scheduled tasks every minute

[Timer]
OnCalendar=*-*-* *:*:00
AccuracySec=1s
Persistent=false
Unit=sahil-schedule.service

[Install]
WantedBy=timers.target
```

`Persistent=false` matches the framework's no-back-fill rule: a run missed
while the machine was down is not replayed. Keep `AccuracySec` small, and
add `RandomizedDelaySec=10` if many hosts share one trigger (overlap locks
make that safe; this only spreads load).

### Environment variables

| Variable | Purpose |
|----------|---------|
| `SCHEDULE_TIMEZONE` | Default task timezone (default: `APP_TIMEZONE`) |
| `SCHEDULE_LOCK` | `file` or `database` (default `file`) |
| `SCHEDULE_LOCK_PATH` | Lock directory for the file driver |
| `SCHEDULE_LOCK_PREFIX` | Advisory-lock name prefix for the database driver |

### Tests

- `tests/Scheduler/CronExpressionTest.php` — field parsing, steps, ranges,
  lists, names, the day rule, rejection of malformed expressions.
- `tests/Scheduler/TaskTest.php` — frequency helpers, timezone wall-clock
  matching, DST spring-forward and fall-back behaviour, next-run output.
- `tests/Scheduler/ScheduleTest.php` — registration, duplicate names,
  timezone inheritance, due selection.
- `tests/Scheduler/ScheduleRunnerTest.php` — due/not-due, late and missed
  minutes, overlap skips, lock release on success/failure/timeout, failure
  reporting and `--only`.
- `tests/Scheduler/FileLockTest.php`, `DatabaseLockTest.php` — lock
  acquisition/release, non-re-entrancy, dialect normalisation.
- `tests/Scheduler/TimeoutGuardTest.php` — measured overrun and real
  interruption when `pcntl` is available.

## Mail & notifications (Phase 17)

Mail is split into a small, replaceable core: a **transport contract**, a
message value object, and a mailer that decides between immediate delivery
and optional queueing. SMTP is implemented with
[`symfony/mailer`](https://symfony.com/doc/current/mailer.html) (MIT,
maintained), which owns the hard parts — MIME encoding, header folding,
SMTP AUTH, STARTTLS/implicit TLS and error responses — behind
`Sahil\Contracts\Mail\TransportContract`. Everything application-facing is
framework code and can be pointed at a provider adapter instead.

```php
use Sahil\Contracts\Mail\MailerContract;
use Sahil\Mail\Html;
use Sahil\Mail\Message;

$message = Message::make()
    ->to('ada@example.com', 'Ada')
    ->subject('Your account is ready')
    ->text("Hello Ada,\n\nYour account is ready.")
    ->html('<p>Hello '.Html::escape($user->name).'</p>');

$receipt = app()->container()->make(MailerContract::class)->send($message);

$receipt->status;      // Sahil\Mail\MailStatus::Accepted
$receipt->describe();  // “accepted by the next hop via [smtp] — …”
```

`Message` validates as it is built: addresses must be valid ASCII mailboxes
without control characters, subjects and custom headers cannot contain line
breaks (so header injection is impossible), and reserved headers such as
`Subject` or `Bcc` cannot be set directly. A message needs a sender, at least
one recipient, a subject and a body before any transport sees it.

### Text, HTML and safe rendering

A message may carry text, HTML or both; the mailer always produces both
parts, so clients get `multipart/alternative`:

- **text only** → the HTML alternative is generated by
  `Html::fromText()`, which escapes the text first. Text can never inject
  markup or scripts.
- **HTML only** → the plain-text alternative is generated by
  `Html::toText()` (links keep their target, lists become dashes, tags are
  stripped, entities decoded).
- **both** → used as authored.

Markup passed to `html()` is **trusted, application-authored HTML**. HTML mail
cannot be sanitised into something safe, so the framework does not pretend
to: interpolate every value with `Html::escape($value)` (or use the generated
HTML alternative). Never pass user input to `html()` unescaped.

### Delivery states

A receipt states exactly what was observed. The distinction is deliberate:

| Status | Meaning | Produced by |
|--------|---------|-------------|
| `queued` | Stored for later delivery; **no transport has attempted it** | `queue()` |
| `accepted` | The next hop (SMTP server) acknowledged the message with `250` after `DATA` — it has taken responsibility, not delivered it | `smtp` |
| `sent` | A provider confirmed it took the message and returned an id | provider/API transports |
| `delivered` | Delivery to the mailbox was confirmed by the receiving side | provider feedback (future) |
| `suppressed` | Deliberately not transmitted | `log`, `array` |
| `failed` | The transport refused or failed; nothing was accepted | any transport |

**Queued is not sent, and accepted is not delivered.** SMTP offers no
receipt for mailbox delivery; claiming one would be dishonest. Bounce and
complaint handling therefore belongs to the provider or the mail server, not
to this layer.

### Transports

| `MAIL_TRANSPORT` | Behaviour |
|------------------|-----------|
| `smtp` | Real delivery through SMTP (`smtp` block in `config/mail.php`) |
| `log` (default) | Development: records the attempt and transmits nothing |
| `array` | In-memory: keeps messages for tests/local inspection |

A custom transport (a provider HTTP API, a different SMTP client) is one
class implementing `TransportContract`; register it with
`MailManager::extend('name', $transport)` and select it by name. No other
code changes.

SMTP settings (`config/mail.php` → `mail.transports.smtp`):

| Key | Meaning |
|-----|---------|
| `host`, `port` | Server address; 587 for STARTTLS, 465 for implicit TLS |
| `security` | `tls` (STARTTLS, **required** — never silently downgrades), `ssl` (implicit TLS) or `none` (plaintext, development only) |
| `auth.username`, `auth.password` | SMTP credentials; set both or neither |
| `timeout` | Socket timeout in seconds (`0` = PHP default) |
| `local_domain` | EHLO domain (optional) |

A transport that cannot be configured (missing host, unsupported security
mode, half a credential pair) fails with one clear message before connecting.
No `From` header is invented: `mail.from.address` (or `Message::from()`) must
be set, otherwise sending fails.

### Queued delivery (optional)

Synchronous `send()` never touches the queue. Deferred delivery is opt-in:

```bash
MAIL_QUEUE=true            # enables queue() and queued notifications
MAIL_QUEUE_NAME=emails     # queue the job lands on
bin/sahil queue:work --queue=emails
```

```php
$receipt = $mailer->queue($message, 30);   // MailStatus::Queued
$receipt->queueId;                          // store id, for correlation
```

Queuing a message stores a `SendMailJob` (the composed message plus the
chosen transport) in `jobs`; the worker sends it later and retries per the
queue policy. Calling `queue()` while `MAIL_QUEUE=false` fails loudly instead
of silently sending or dropping the message. Retries are **at-least-once**: a
failure after the server accepted a message can produce a duplicate, so
application code that cannot tolerate that should de-duplicate on its own
idempotency key (for example a custom `X-Entity-Ref-ID` header).

### Notifications

Notifications are channel-agnostic: a notification names channels, channels
know how to deliver, and `Notifier` routes. Mail ships today; SMS, chat or
push are added by implementing `ChannelContract` and registering the channel.

```php
use Sahil\Contracts\Notifications\NotifiableContract;
use Sahil\Notifications\Notification;
use Sahil\Mail\Message;

final class InvoiceReady extends Notification
{
    public function __construct(private readonly string $number) {}

    public function toMail(NotifiableContract $notifiable): Message
    {
        return Message::make()
            ->subject('Invoice '.$this->number.' is ready')
            ->text('You can download invoice '.$this->number.'.');
    }
}

$report = app()->container()->make(\Sahil\Notifications\Notifier::class)
    ->send($user, new InvoiceReady('INV-2026-001'));

$report->forChannel('mail')?->status;   // accepted | queued | skipped | failed
$report->failed();
```

The notifiable implements `routeNotificationFor('mail')` (returning the
address, or `null` to skip). The `mail` channel adds that address when the
message names no recipient and applies the configured sender. Channels are
delivered independently: one failure is logged and reported without stopping
the others, and an unknown channel name is a configuration error. With
`MAIL_QUEUE=true` the mail channel queues instead of sending, and the report
says `queued`.

### Logging and redaction

Outcome events are structured and content-free:

- `mail.queued`, `mail.accepted`, `mail.sent`, `mail.delivered`,
  `mail.suppressed` (info) and `mail.failed` (error);
- `notification.failed` (error) per failing channel;
- context: transport, status, recipient **count**, message/queue id, and a
  sanitised detail — never the subject, body or recipient addresses;
- credentials are scrubbed even when a library puts them in an error:
  `AUTH PLAIN/LOGIN` payloads, `password=…` / `token: …` assignments,
  `scheme://user:pass@host` URLs, and the configured SMTP username/password;
- `config:show mail` prints the transport tree with `password` as
  `[redacted]`; the configured SMTP username/password are also scrubbed from
  transport error text before it is logged.

### Development configuration

```bash
MAIL_TRANSPORT=log
MAIL_FROM_ADDRESS=dev@localhost
```

`log` is the default: it exercises composition, validation and the logging
path without a network or credentials, and its receipt says `suppressed`. To
inspect real MIME, run a local plaintext SMTP catcher and point at it:

```bash
MAIL_TRANSPORT=smtp
MAIL_HOST=127.0.0.1
MAIL_PORT=1025
MAIL_SECURITY=none    # plaintext is for local development only
MAIL_FROM_ADDRESS=dev@localhost
```

In tests, select the `array` transport (or call `MailManager::extend()` with
a recording transport) and assert the composed message: nothing leaves the
process.

### Production configuration

```bash
APP_ENV=production
MAIL_TRANSPORT=smtp
MAIL_HOST=smtp.your-provider.example
MAIL_PORT=587
MAIL_SECURITY=tls          # STARTTLS is required; use ssl only on port 465
MAIL_USERNAME=...          # from the secret store, never committed
MAIL_PASSWORD=...
MAIL_TIMEOUT=15
MAIL_FROM_ADDRESS=no-reply@your-domain.example
MAIL_FROM_NAME=Your App
MAIL_QUEUE=true
MAIL_QUEUE_NAME=emails
```

- Put credentials in the environment or your secret manager; `.env` is
  git-ignored.
- Send from a domain you control and publish SPF, DKIM and DMARC for it. The
  provider must authorise the sender; otherwise messages are accepted by the
  relay and later bounced or spam-filed.
- Keep `security=tls` (or `ssl` on 465). `none` sends credentials and mail in
  clear text and must not be used in production.
- Queue outbound mail and run `queue:work --queue=emails` under systemd or a
  process supervisor. Watch `mail.failed` and the `failed_jobs` table; the
  queue retries transient transport errors, and queued work is at-least-once.
- Receipts stop at `accepted` by design. Delivery, bounces and complaints are
  confirmed by the mail provider or server and must be wired to that
  provider's feedback (webhooks/DSN), not inferred from an accepted SMTP
  reply.
- Verify a deployment with one real send to a mailbox you control before
  enabling notifications.

### Environment variables

| Variable | Purpose |
|----------|---------|
| `MAIL_TRANSPORT` | `smtp`, `log` (default) or `array` |
| `MAIL_HOST`, `MAIL_PORT` | SMTP endpoint (defaults `127.0.0.1:587`) |
| `MAIL_SECURITY` | `tls` (default), `ssl` or `none` |
| `MAIL_USERNAME`, `MAIL_PASSWORD` | SMTP credentials (both or neither) |
| `MAIL_TIMEOUT` | Socket timeout in seconds (default `15`) |
| `MAIL_EHLO_DOMAIN` | EHLO domain (optional) |
| `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | Default sender identity |
| `MAIL_QUEUE` | Enable deferred delivery (default `false`) |
| `MAIL_QUEUE_NAME` | Queue used by deferred delivery (default `emails`) |

### Tests

- `tests/Mail/MessageTest.php` — composition, address/header injection,
  sendability rules, serialization of a queued message.
- `tests/Mail/AddressTest.php` — valid mailboxes, quoted names, invalid
  addresses, control characters, length limits.
- `tests/Mail/HtmlTest.php` — escaping, text→HTML and HTML→text
  alternatives, entity and list handling, round trip.
- `tests/Mail/MailManagerTest.php` — default and explicit transports,
  sender identity, validation before transport, sanitised failures,
  content-free logging, queueing without sending, disabled-queue and
  queue-failure behaviour.
- `tests/Mail/SmtpTransportTest.php` — message→MIME mapping (from/to/cc/bcc/
  reply-to/headers), generated alternatives, Bcc handling, wrapped errors,
  configuration errors.
- `tests/Mail/SmtpDialogueTest.php` — a real socket conversation against a
  local scripted SMTP server: EHLO, AUTH PLAIN, envelope, multipart DATA,
  accepted/rejected authentication and rejected recipients.
- `tests/Mail/SendMailJobTest.php` — queued job delivery, serialized jobs,
  retryable failures.
- `tests/Mail/LocalTransportsTest.php` — suppression and in-memory capture.
- `tests/Mail/SanitizerTest.php`, `tests/Mail/MailProviderTest.php` — log
  sanitisation and container/config wiring with redacted credentials.
- `tests/Notifications/NotifierTest.php` — mail routing, queued
  notifications, skipped routes, unknown channels, channel isolation and
  sanitised failure logging.

## Roadmap

- Phase 2 — Dependency injection & application lifecycle (done)
- Phase 4 — HTTP kernel & routing (done)
- Phase 5 — Configuration, environment, logging & exception handling (done)
- Phase 6 — Validation & form handling (done)
- Phase 7 — Database connections & query builder (done — integration gated on provided credentials)
- Phase 8 — ORM & data models (done — real-database integration on MySQL + PostgreSQL)
- Phase 9 — Migrations & seeding (done — real-database integration on
  MySQL + PostgreSQL, including locking and failed-migration behaviour)
- Phase 10 — Queued jobs (done — database-backed queue, retries, failed-job
  store, worker and operator commands)
- Phase 14 — File storage & uploads (done — local driver, public/private
  disks, upload validation, remote-adapter extension point)
- Phase 16 — Task scheduler (done — cron expressions, timezones/DST,
  overlap locks, timeouts, failure reporting, inspection commands)
- Phase 17 — Mail & notifications (done — transport contract, SMTP via
  `symfony/mailer`, safe text/HTML rendering, optional queued delivery,
  channel-based notifications, credential-free logging)
- Upcoming: security layer (CSRF, sessions), a full console engine, view
  layer.

## Contributing, security and changelog

- [CONTRIBUTING.md](CONTRIBUTING.md) — development setup and quality gates
  (`composer lint`, `test`, `analyse`, `check-style`).
- [SECURITY.md](SECURITY.md) — how to report a vulnerability privately, and
  the supported versions.
- [CHANGELOG.md](CHANGELOG.md) — release history in Keep a Changelog format.
- [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md) — community expectations.

## License

SahilPHP is released under the MIT License. See [LICENSE](LICENSE) for the
full text.
