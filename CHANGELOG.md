# Changelog

## [Unreleased]

### Added

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
