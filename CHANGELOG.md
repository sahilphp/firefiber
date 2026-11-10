# Changelog

## [Unreleased]

### Added

- `ComponentRenderer`, bound as `Sahil\Contracts\View\ComponentRendererContract`,
  so `@component(...)` and `<fiber:component ... />` resolve short names below
  `App\Firefiber\Components` (including nested names and fully-qualified class
  names), validate properties, and render through `PageRenderer`.
- Circular nesting protection: a component that reaches itself through
  `children()` now throws instead of recursing until PHP exhausts the stack.

### Fixed

- `firefiber:component --inline` is a flag again (`--inline` without a value no
  longer fails with "requires a value"), and inline components are written to
  `<Class>.php` so PSR-4 autoloading finds them. The inline stub now imports
  `UnknownActionException`.

- Composer archives no longer include the development `composer.lock`,
  PHPUnit configuration, or local `*.bak`/backup middleware files.
- The package is explicitly a library and refuses
  `composer create-project sahilphp/firefiber` through
  `post-create-project-cmd`, exiting 1 with installation guidance.
