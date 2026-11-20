# Changelog

## [Unreleased]

### Added

- **Component events.** Actions can now dispatch named events across the
  rendered component tree: `dispatch()` broadcasts to every connected
  listener, `dispatchSelf()`/`dispatchUp()`/`dispatchDown()` target self,
  ancestors or descendants, `dispatchTo(Class::class, …)` targets mounted
  components of a class, and `dispatchBrowserEvent()` reaches JavaScript
  only. `emit()` is preserved as a `dispatch()` alias. Listeners are
  declared with `#[On('name')]` (payload keys map onto parameters by
  name) or the existing `$listeners` map — several listeners may handle
  one event. Delivery walks the authoritative parent/child link records
  in both directions, hydrates and persists each target under the normal
  signed-snapshot rules, and reports `delivered` component ids back in
  the response's `events` channel. The browser runtime emits each
  dispatched event as a bubbling CustomEvent on the component root plus a
  `firefiber:<name>` document event; `Firefiber.on()`,
  `Firefiber.dispatch()` and `Firefiber.dispatchTo()` cover the
  browser side.
- **Component-scoped styles.** `Component::styles()` and
  `Component::styleFile()` attach CSS that is scoped beneath the
  component's stable `[data-fx]` attribute by a real CSS parser
  (`Styles\ScopedStylesheet`) — selector lists, pseudo-classes, media /
  supports / layer / container / scope at-rules recurse, while
  `@keyframes`, `@font-face` and friends pass through untouched.
  `styleFile()` resolves stylesheets under `resources/` with traversal
  protection. `globalStyles()` emits explicit unscoped CSS. Style tags
  are deduplicated per render on the server and by `data-firefiber-style`
  key after every DOM swap in the runtime.
- **Upload API.** `Component::uploadedFile()`/`uploadedFiles()` wrap the
  trusted staged-upload records in `Uploads\UploadedFile` objects with
  `originalName()`, `size()`, `mimeType()`, `temporaryPath()`,
  `contents()`, `store()` (permanent promotion synced to state + sidecar)
  and `delete()`. `firefiber:cleanup-uploads` reclaims expired staged
  directories from the storage disks.
- Constructor property defaults on `Component` subclasses (`$state`,
  `$actions`) are now preserved — constructor arguments only override
  when actually provided, matching `Page`.

### Added

- First-class page architecture: `Page` is now a full stateful component
  (`Page extends Component`). A page owns signed-snapshot state, `$actions`
  allow-listed methods, `$modelable`/`fiber:model` bindings, forms and
  validation, query sync, and every other component capability — and it is
  rendered as its own reactive root (`firefiber_attributes` carries the
  page snapshot, action endpoint and CSRF token). `mount()` runs exactly
  once on the initial GET with the matched route parameters; subsequent
  action requests hydrate server-side state and run `booted()` instead.
  Route parameters are captured into page state and stay available through
  `routeParams()` after hydration. `Page::layout()`/`contentView()` support
  document composition and opt-in fragment re-rendering.
- `Component::handleAction()` has a safe default dispatcher: an allow-listed
  action invokes the same-named public method declared on the subclass
  (list payloads positional, associative payloads named). Framework and
  lifecycle methods can never be reached — dispatch is restricted to
  methods first declared below `Component`/`Page`, and a reserved-name
  list (mount, hydrate, render, view, show, authorize, …) is rejected
  outright. Subclasses may still override `handleAction()` for manual
  dispatch.
- `Link::firefiber()` mounts a page class on a GET route with no
  controller in between — `Link::firefiber(HomePage::class)` uses the
  page's own `path()`, and `Link::firefiber('/posts/{id}', ShowPage::class)`
  mounts at an explicit path template. `Page` implements the framework's
  `MountablePageContract`.
- `PageRenderer::render()` accepts extra template data so callers can
  pass variables alongside the page-supplied context.
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

### Removed

- `PageRegistry::registerRoutes()` and provider auto-registration are gone:
  discovery under `App\Firefiber\Pages` now only organizes and resolves
  page classes — every reachable page must be mounted explicitly through
  `Link::firefiber()`, so no page is ever exposed publicly by accident.
- The `FireAdapter` render environment and its `fire`/`fiber` unified-render
  registrations never shipped and are gone: Firefiber pages are not
  controller-rendered. They own their lifecycle — route mount, state,
  components, view — and mount via `Link::firefiber()`. The provider no
  longer re-binds `RenderManager`, leaving that binding to the application.

### Fixed

- Action requests failed with a CSRF error whenever the session rotated its
  token after the page render (the default `issueToken()` rotation). CSRF
  validation now goes through `Session::validateToken()`, which accepts
  the current token plus the bounded history ring.

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
