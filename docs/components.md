# Firefiber components

Firefiber components are server-side PHP classes. Page routes discover page
classes, and the Firefiber provider renders their component instances inside
Firefiber component roots.

## Component and state

```php
final class Counter extends Component
{
    public function __construct(array $properties = [])
    {
        parent::__construct(['count' => 0], ['increment'], $properties);
    }

    public function render(): string
    {
        $count = (int) ($this->state['count'] ?? 0);

        return '<button type="button" fiber:click="increment">Increment</button>'
            .'<span data-firefiber-bind="count">'.$count.'</span>';
    }

    public function handleAction(string $action, array $payload): array
    {
        if ($action !== 'increment') {
            throw new UnknownActionException($action, $this->id());
        }

        $count = (int) ($this->state['count'] ?? 0) + 1;
        $this->updateState(['count' => $count]);

        return ['count' => $count];
    }
}
```

Component state is persisted by Firefiber after a successful action. A browser
snapshot records the state it came from, but actions always hydrate from the
server-side state record, not from untrusted browser data.

## Properties and nested components

Declare server-supplied properties separately from mutable state:

```php
final class StepCounter extends Component
{
    protected array $propertyDefinitions = [
        'step' => ['type' => 'int', 'required' => true, 'min' => 1],
        'label' => ['type' => 'string', 'default' => 'Counter'],
    ];

    public function __construct(array $properties = [])
    {
        parent::__construct(['count' => 0], ['increment'], $properties);
    }

    public function render(): string
    {
        return '<strong>'.htmlspecialchars((string) $this->property('label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</strong>';
    }

    public function handleAction(string $action, array $payload): array
    {
        // ...
    }
}
```

Create two instances with different immutable properties from a page or parent:

```php
public function components(): array
{
    return [
        new StepCounter(['step' => 1, 'label' => 'Small']),
        new StepCounter(['step' => 5, 'label' => 'Large']),
    ];
}
```

A parent can include children in its own render tree:

```php
final class CounterParent extends Component
{
    public function __construct(private readonly ?Component $child = null)
    {
        parent::__construct(['last_count' => 0]);
    }

    public function children(): array
    {
        return $this->child === null ? [] : [$this->child];
    }

    protected array $listeners = ['count-changed' => 'onCountChanged'];

    public function onCountChanged(array $payload): void
    {
        $this->updateState(['last_count' => (int) ($payload['count'] ?? 0)]);
    }

    public function render(): string
    {
        return '<span data-firefiber-bind="last_count">0</span>';
    }

    public function handleAction(string $action, array $payload): array
    {
        throw new UnknownActionException($action, $this->id());
    }
}
```

The renderer records the parent link server-side. There is no global event bus;
events are delivered only to explicitly linked parents.

## Parent-child events

Children emit named events after successful action work:

```php
protected array $emits = ['count-changed'];

protected function eventPayloadRules(): array
{
    return [
        'count-changed' => [
            'count' => 'required|integer|min:0',
        ],
    ];
}

public function handleAction(string $action, array $payload): array
{
    // update state
    $this->emit('count-changed', ['count' => $count]);

    return ['count' => $count];
}
```

After the child action, Firefiber hydrates each linked parent, validates the
event payload, invokes its listener, persists it, and returns fresh parent
snapshots. The browser runtime applies those snapshots under their individual
component roots and emits `firefiber:parent-updated`.

## Reactive forms and validation

Only keys declared in `$modelable` can be written through `fiber:model`:

```php
final class ProfileForm extends Component
{
    protected array $modelable = ['email', 'name', 'age'];

    public function __construct()
    {
        parent::__construct(['email' => '', 'name' => '', 'age' => 0], []);
    }

    protected function formRules(): array
    {
        return [
            'email' => 'required|email',
            'name' => 'required|string|max:80',
            'age' => 'nullable|integer|min:0|max:130',
        ];
    }

    public function handleAction(string $action, array $payload): array
    {
        return [];
    }
}
```

For a custom submit action, validate the complete submission server-side:

```php
$validated = $this->validateForm([
    'email' => $payload['email'] ?? '',
    'name' => $payload['name'] ?? '',
    'age' => $payload['age'] ?? null,
]);
$this->updateState($validated);
```

Validation failures return a `422` response with framework-generated field
errors. The browser runtime writes matching messages into elements such as:

```html
<p data-firefiber-error="email"></p>
<p fiber:error="email"></p>
```

and sets `aria-invalid` on the error element. Successful responses clear
those elements. Client-side code never replaces server validation.

## Loading, disabled, and error state

The runtime exposes `fiber:loading`, `fiber:target`, and `fiber:disabled`. For
example, `fiber:target="save"` limits an indicator to the named action, and
`fiber:disabled` removes the control from interaction while its action is in
flight. Loading state always terminates in a `finally` path, including after a
network failure, so controls recover from ordinary failures.

Duplicate submissions for the same component and action are suppressed while
pending. A form submit should still keep its server action allow-listed:

```php
<form fiber:submit="save">
    <button type="submit" fiber:disabled>Save</button>
</form>
```

## Lifecycle

Hook execution order is:

1. `mount()` — once, after constructor state and properties initialize.
2. `booted()` — once, after a component hydrates and any trusted properties
   have been restored.
3. `updating($action, $payload)` — before an action executes.
4. `updated($action, $payload, $result)` — after a successful action.
5. `rendering()` — immediately before `render()`.
6. `destroy()` — only when the application explicitly invokes it for server-side
   cleanup. Removing DOM elements does not by itself delete server-held state.

`authorize()` receives the current authenticated user and action name before
the action runs. Returning `false` rejects the action with `403`.

## URL-synchronized state

Declare which state keys may appear in the query string with `$querySync`.
Only these keys are writable through URL hydration and the browser's
back/forward navigation. Keys marked `sensitive` are never exposed and are
never writable.

```php
protected array $querySync = [
    'search'  => ['param' => 'q', 'type' => 'string'],
    'page'    => ['param' => 'page', 'type' => 'int'],
    'sort'    => ['param' => 'sort', 'type' => 'string'],
    'filters' => ['param' => 'filter', 'type' => 'string', 'multiple' => true],
];
```

Supported types are `string`, `int`, and `bool`. The server coerces and
validates every value; invalid values are rejected and never mutate state.
On back/forward navigation the browser dispatches the built-in
`firefiberQuery` action (not `updateModel`), so only declared, typed
query-sync keys can change. A `null` value (the parameter disappeared from
the URL) resets the key to its constructor default.

The component root advertises the configuration in
`data-firefiber-query-sync` so the browser can keep the address bar current
without trusting or signing URL data.

## Server-driven tables

`Sahil\Firefiber\Data\DataTable` paginates, sorts, filters, and searches a
plain in-memory dataset with the same server-side rules you should apply to
database-backed tables:

```php
use Sahil\Firefiber\Data\DataTable;

$table = new DataTable($rows, [
    'perPage'         => 25,
    'sortableFields'  => ['name', 'created_at'],
    'searchableFields'=> ['name', 'email'],
    'filters'         => ['status' => ['operator' => 'eq']],
    'defaultSort'     => ['field' => 'created_at', 'direction' => 'desc'],
]);

$page = $table->paginate($this->state);
```

`paginate()` returns a `PaginatedResult` with `data`, `total`,
`currentPage`, `perPage`, `lastPage`, `from`, `to`, plus the applied
`search`, `sort`, `direction`, and `filters`. Page numbers and sizes clamp
to safe bounds. Sort fields outside the allow-list fall back to the default
sort field; directions coerce to `asc` or `desc`. Unknown filter keys are
ignored. Search requires at least one `searchableFields` entry.

## Polling

Components can refresh themselves on an interval:

```php
protected int $pollIntervalMs = 5000;        // 0 disables polling
protected bool $pollPausedWhenHidden = true; // pause in background tabs

public function poll(): void
{
    // Refresh server-authoritative state.
}

public function shouldContinuePolling(): bool
{
    return $this->state['status'] !== 'complete';
}
```

The browser dispatches the built-in `firefiberPoll` action on the interval.
Polling requests are silent (no global loading indicator) and the runtime
backs off on failures. Components that do not configure an interval reject
poll requests.

## Lazy loading

Defer expensive rendering until a component is needed:

```php
protected bool $lazy = true;
protected string $lazyTrigger = 'visible'; // IntersectionObserver
protected string $lazyPlaceholder = '<p class="firefiber-lazy-placeholder">Loading…</p>';

public function load(): void
{
    // Populate state before the deferred render.
}
```

The initial render emits only the placeholder and a signed snapshot. When
the trigger fires, the browser dispatches the built-in `firefiberLoad`
action and the server returns fresh markup. `Firefiber.loadLazy(id)` and
`Firefiber.retryLazy(id)` trigger a load manually. Components that do not
opt into lazy loading reject load requests.

## Optimistic UI (opt-in)

Optimistic updates are explicit and DOM-only. Declare the state patch to
apply immediately for specific actions:

```php
protected array $optimisticActions = [
    'toggle' => ['active' => true],
];
```

The runtime applies the patch to bound values, emits `firefiber:optimistic`,
and restores the pre-action snapshot on any failure (`firefiber:rollback`).
The signed `data-firefiber-snapshot` attribute always carries
server-authoritative state; optimistic patches never change it. Out-of-order
responses cannot overwrite a newer authoritative state.

## Secure uploads

Uploads use a dedicated multipart endpoint (`POST /firefiber/uploads` by
default, configurable via `firefiber.upload_path`). The endpoint is
CSRF-exempt from the framework's rotating CSRF middleware but validates the
session CSRF token itself, requires a signed component snapshot, re-hydrates
the component, and re-checks authorization.

Declare allowed uploads per field:

```php
protected array $uploadDefinitions = [
    'avatar' => [
        'maxSize'           => 2 * 1024 * 1024,
        'maxFiles'          => 1,
        'allowedExtensions' => ['png', 'jpg', 'jpeg', 'webp'],
        // or 'allowedMimeTypes' => ['image/png', 'image/jpeg'],
    ],
];
```

The server detects MIME type from file content (not the client), enforces
size and count limits, rejects executable extensions, stores files under a
random name, and verifies the stored file before recording it. The client
filename is never used for storage and client MIME/size are never trusted.

In the browser, add `fiber:upload="avatar"` to a file input or call
`Firefiber.upload(componentId, 'avatar', files)`. Progress and results are
emitted as `firefiber:uploadprogress`, `firefiber:uploadsuccess`, and
`firefiber:uploaderror`.

Promote or remove staged uploads through the built-in component actions
`promoteUpload` / `removeUpload` (they re-check authorization). A
`permanentDirectory` is validated and cannot traverse outside the storage
root.

## Render caching and server re-renders

Large components can opt into fragment caching:

```php
protected array $cacheConfiguration = [
    'ttl'        => 60,
    'scope'      => 'user', // or 'instance'; 'user' is the safe default
    'generation' => 'v1',    // bump to invalidate
];
```

Cache keys include the component class, state, validated properties, scope,
and identity (session/user/tenant as available). User-scoped caches are
isolated per user and session; instance-scoped keys may include the
component id. Nested child markup is never reused from a parent cache.

By default, action responses return state/snapshot only. Set
`$rerenderOnAction = true` to include fresh server-rendered `html` on every
successful action (and on built-in load/poll/query actions, which always
return markup when available). Enable it only when the server must control
the rendered fragment.

## Complete sample page

A page can combine two instances of one component, a parent that reacts to
child events, and a validated form:

```php
final class ProfileDemoPage extends Page
{
    public function path(): string
    {
        return '/firefiber/profile-demo';
    }

    public function title(): string
    {
        return 'Profile demo';
    }

    public function components(): array
    {
        return [
            new CounterParent(new StepCounter(['step' => 2, 'label' => 'Demo'])),
            new ProfileForm(),
        ];
    }

    public function view(): ?string
    {
        return 'firefiber.pages.profile-demo';
    }
}
```

## Security model

The browser root contains no HMAC or signing secret. Requests are protected by:

- the Sahil session and its CSRF token;
- a server-held master HMAC key;
- server-side component state and property metadata;
- snapshot state comparison before hydration;
- action allow-lists and `authorize()` (re-checked for built-in load, poll,
  query, upload, promote, and remove actions);
- request-ID replay protection and per-component locking;
- session and user metadata recorded when the component renders.

Advanced capabilities keep the same boundary:

- URL-synchronized values are validated server-side against declared, typed
  query-sync configuration; sensitive keys are excluded; back/forward
  navigation uses the built-in `firefiberQuery` action, not `updateModel`.
- Polling and lazy-load requests are silent but still signed by snapshot +
  CSRF and authorized by the component.
- The upload endpoint is CSRF-exempt from framework token rotation but
  validates session CSRF itself, requires a signed snapshot, re-authorizes,
  detects MIME from content, randomizes storage names, and never trusts
  client filenames, MIME types, or sizes.
- Optimistic UI is DOM-only and rolls back on failure; render caches are
  opt-in and isolated by scope/identity (user-scoped caches never mix users
  or sessions).

A legacy optional `X-Firefiber-Signature` header, when supplied, is checked as
transport integrity only. It is not authentication: the server derives trust
from the session, CSRF, server-held state, and signed snapshot.
