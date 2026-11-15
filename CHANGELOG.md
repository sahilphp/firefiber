# Changelog

## [Unreleased]

### Added

- The `fire` render environment now resolves page names to Sahil templates
  under `resources/views/firefiber/pages`: `Render::fire('dashboard')`,
  `$this->fire('dashboard')`, and `Response::fire('dashboard', ...)` render
  `firefiber.pages.dashboard`, nested names map to subdirectories
  (`admin/users` → `firefiber.pages.admin.users`), and names that are
  already namespaced (`firefiber.*`) pass through verbatim. Names without a
  matching template still resolve as components (`fire('Counter')`), and an
  unknown name reports the expected template path.
- `fiber` is registered as an alias of the `fire` render environment, so
  `Render::fiber()`, `$this->fiber()`, and `Response::fiber()` work
  identically.
- `ComponentRenderer::has()` reports whether a name resolves to a component
  without throwing.
- Page classes without a `view()` override are matched to a conventional
  template by class name (`App\Firefiber\Pages\Admin\UsersPage` →
  `firefiber.pages.admin.users`) before falling back to the built-in
  document shell.
- `config.reload` (and `Firefiber.configure({ reload })`) overrides the
  automatic page reload that follows a lost component state; the same hook is
  used by tests.
- `ComponentRenderer`, bound as `Sahil\Contracts\View\ComponentRendererContract`,
  so `@component(...)` and `<fiber:component ... />` resolve short names below
  `App\Firefiber\Components` (including nested names and fully-qualified class
  names), validate properties, and render through `PageRenderer`.
- Circular nesting protection: a component that reaches itself through
  `children()` now throws instead of recursing until PHP exhausts the stack.

### Fixed

- Multipart uploads no longer fail with a `TypeError` on framework v2:
  `FirefiberMiddleware::normalizeUploadForm()` accepts the request's
  `Sahil\Http\Form` object as well as a plain array.
- `fiber:click` sends `data-firefiber-payload` as the action payload instead of
  an empty object, so paging and sorting buttons (for example the starter
  admin table's) reach `handleAction()` with their arguments. The value may sit
  on the element or an enclosing element; clicks still do not serialize form
  controls.
- Actions on a page whose signed snapshot aged out (default 300 s) no longer
  fail with a silent `409 Conflict`: the runtime fetches the authoritative
  snapshot (`getSnapshot`), applies it, emits `firefiber:resync` and replays
  the action once. When the server no longer holds state for the component
  (cleared storage, expired state, redeploy), the runtime emits
  `firefiber:expired` with `recoverable: false` and reloads the page once —
  guarded to one automatic reload per page per 15 s — instead of leaving the
  control permanently dead. Nothing is ever replayed blindly.
- `firefiber:component --inline` is a flag again (`--inline` without a value no
  longer fails with "requires a value"), and inline components are written to
  `<Class>.php` so PSR-4 autoloading finds them. The inline stub now imports
  `UnknownActionException`.

- Composer archives no longer include the development `composer.lock`,
  PHPUnit configuration, or local `*.bak`/backup middleware files.
- The package is explicitly a library and refuses
  `composer create-project sahilphp/firefiber` through
  `post-create-project-cmd`, exiting 1 with installation guidance.
