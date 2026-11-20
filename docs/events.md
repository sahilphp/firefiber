# Firefiber events

Components communicate through server-dispatched events: an action on one
component can notify its parent, its children, sibling components, or the
browser — without direct references between them.

## Dispatching

Events are queued inside actions and delivered by the action pipeline:

```php
final class PostForm extends Component
{
    protected array $actions = ['save'];

    public function save(): void
    {
        $post = /* … persist … */;

        // To every listener in the rendered tree (parent, siblings,
        // descendants — and a `post-created` CustomEvent in the browser).
        $this->dispatch('post-created', ['postId' => $post->id]);
    }
}
```

| Method | Server delivery | Browser event |
|---|---|---|
| `dispatch($name, $payload)` | Every listener in the connected tree | Yes |
| `dispatchSelf($name, $payload)` | This component's listeners only | Yes |
| `dispatchUp($name, $payload)` | Ancestors only | Yes |
| `dispatchDown($name, $payload)` | Descendants only | Yes |
| `dispatchTo(Class::class, $name, $payload)` | Mounted components of that class | Yes |
| `dispatchBrowserEvent($name, $payload)` | None — browser only | Yes |
| `emit($name, $payload)` | Alias of `dispatch()` | Yes |

Payloads are plain arrays. `eventPayloadRules()` can validate them per
event name before they are queued:

```php
protected function eventPayloadRules(): array
{
    return ['post-created' => ['postId' => 'required|integer']];
}
```

`$emits` optionally allowlists the event names a component may dispatch.

## Listening

Declare listeners with `#[On]` — payload keys map onto parameters by
name; missing required parameters fail, surplus keys are ignored:

```php
use Sahil\Firefiber\Attributes\On;

final class PostList extends Component
{
    #[On('post-created')]
    public function refreshPosts(int $postId): void
    {
        $this->updateState(['lastId' => $postId]);
    }
}
```

The `$listeners` map remains supported; its methods receive the whole
payload array. An event may have several listeners — every `#[On]` method
and the mapped method all run:

```php
protected array $listeners = ['post-created' => 'onPostCreated'];

public function onPostCreated(array $payload): void { /* … */ }
```

Listener methods must be public, non-static, and declared on the
component's own class hierarchy — framework internals are never callable.

## Propagation

Events travel the *rendered* tree recorded by the parent registry —
the same authoritative server-side linkage hydration uses, so forged
snapshots cannot fabricate relationships:

- `dispatch()` reaches every mounted component connected to the emitter
  (its page, ancestors, descendants, and siblings under the same page).
- Independently mounted components that share no rendered ancestry do
  not see each other's server-side events; they can still observe the
  browser CustomEvent.

Listener-side state changes persist: updated components return fresh
signed snapshots (`parentSnapshots`), and event entries in the response
carry `delivered` — the component ids that handled the event.

## Browser integration

Every dispatched event also surfaces as a bubbling `CustomEvent` on the
emitting component's root (`post-created`) and as a namespaced
`firefiber:post-created` on `document`. Listen from JavaScript:

```js
// Global listener; returns an unsubscribe function.
const off = window.Firefiber.on('post-created', (detail) => {
    console.log(detail.payload.postId);
});

// Or any DOM listener — the plain event name bubbles from the root.
document.addEventListener('post-created', (e) => console.log(e.detail));
```

`window.Firefiber.dispatch(name, payload)` and
`window.Firefiber.dispatchTo(componentId, name, payload)` emit browser
events only — they never reach the server. Server listeners are invoked
exclusively by PHP inside an action, so forged client events cannot
mutate component state.

## Security

- Event names must match `[A-Za-z][A-Za-z0-9._:-]*`.
- Payloads are validated by `eventPayloadRules()`/`validateEventPayload()`
  before dispatch and again before listener invocation.
- Listeners resolve from trusted class metadata (`#[On]` + `$listeners`),
  never from request data — no class or method names cross the wire.
- Delivery hydrates targets from authoritative server-side state under
  the same signed-snapshot, CSRF and ownership checks as actions.
- Upload-style private fields (session/user binding) never enter queued
  events; `delivered` ids are server-generated.
