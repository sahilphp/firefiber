<?php

declare(strict_types=1);

namespace Sahil\Firefiber;

use JsonException;
use Sahil\Contracts\Auth\AuthenticatableContract;
use Sahil\Firefiber\Exceptions\InvalidStateException;
use Sahil\Firefiber\Exceptions\UnknownActionException;
use Sahil\Firefiber\Exceptions\ValidationException;
use Sahil\Validation\Validator;

/**
 * Base class for Firefiber components.
 *
 * Components are the fundamental reactive units in Firefiber. Each component
 * manages its own state and handles actions received from the browser.
 *
 * @template TState of array<string, mixed>
 */
abstract class Component
{
    /** @used-by subclasses */
    public const string COMPONENT_CLASS = 'firefiber-component';

    /** Action name used by the browser runtime's fiber:model directive. */
    public const string MODEL_ACTION = 'updateModel';

    /** Built-in action the browser runtime uses to fetch deferred content. */
    public const string LOAD_ACTION = 'firefiberLoad';

    /** Built-in action the browser runtime uses for polling refreshes. */
    public const string POLL_ACTION = 'firefiberPoll';

    /**
     * Built-in action the browser runtime uses to push URL-synced values
     * back to the server (back/forward navigation). Writable keys are the
     * component's declared query-sync configuration only.
     */
    public const string QUERY_ACTION = 'firefiberQuery';

    /**
     * Unique component identifier.
     *
     * This ID is generated server-side and transmitted to the browser in the
     * initial component snapshot. It is used to identify the component in
     * subsequent requests.
     *
     * @var string
     */
    protected string $id;

    /**
     * Current component state.
     *
     * State is serialized and transmitted to the browser in the initial
     * snapshot. Subsequent updates only transmit the changed portions.
     *
     * @var array<string, mixed>
     */
    protected array $state = [];

    /** Constructor-provided state, used to reset URL-hydrated keys. */
    private array $initialState = [];

    /**
     * List of actions this component can handle.
     *
     * Actions are identified by string names and mapped to callable handlers.
     * Only listed actions may be invoked from the browser.
     *
     * @var list<string>
     */
    protected array $actions = [];

    /**
     * State keys that fiber:model is allowed to update.
     *
     * This is an explicit allow-list: browser input can only ever write the
     * keys declared here, never protected properties or internal state.
     *
     * @var list<string>
     */
    protected array $modelable = [];

    /**
     * Public URL synchronization configuration.
     *
     * Keys are component state or property keys. Values are either a query
     * parameter name or an array with `param`, `type`, `multiple` and
     * `sensitive` options.
     *
     * @var array<string, mixed>
     */
    protected array $querySync = [];

    /**
     * Optional optimistic state patches for eager UI interactions.
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $optimisticActions = [];

    protected bool $lazy = false;

    protected string $lazyTrigger = 'visible';

    protected string $lazyPlaceholder = '<p class="firefiber-lazy-placeholder">Loading…</p>';

    /**
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $uploadDefinitions = [];

    /**
     * @var array<string, mixed>
     */
    protected array $cacheConfiguration = [];

    /**
     * When true, every successful action response carries freshly rendered
     * component markup (`html`) so the browser can replace the fragment.
     * Opt-in: server re-rendering costs a render pass per action.
     */
    protected bool $rerenderOnAction = false;

    /** Polling interval in milliseconds. 0 disables polling. */
    protected int $pollIntervalMs = 0;

    /** Pause polling while the browser document is hidden. */
    protected bool $pollPausedWhenHidden = true;

    /**
     * Immutable, server-supplied properties.
     *
     * Property definitions are deliberately distinct from mutable state:
     * each definition may declare a type, a default, whether the value is
     * required, and simple bounds. Unsupported keys are rejected once a
     * component declares definitions; undeclared components may still receive
     * arbitrary initial properties for backwards compatibility.
     *
     * @var array<string, array{type?: string, required?: bool, default?: mixed, nullable?: bool, min?: mixed, max?: mixed, length?: string, in?: list<mixed>}>
     */
    protected array $propertyDefinitions = [];

    /** @var array<string, mixed> */
    protected array $properties = [];

    /** @var array<string, mixed> */
    protected array $trustedMetadata = [];

    /** @var list<string> */
    protected array $emits = [];

    /** @var array<string, string> */
    protected array $listeners = [];

    /** @var list<array{name: string, payload: array}> */
    protected array $queuedEvents = [];

    protected bool $hydrated = false;

    private static bool $hydrationInProgress = false;

    private bool $bootedPending = false;

    /**
     * Create a component for hydration.
     *
     * Required initial properties are intentionally not supplied here: trusted
     * properties are restored from the server-side state record immediately
     * after construction. This lets a property-bearing component be safely
     * instantiated without a browser-supplied property payload.
     */
    public static function newForHydration(): static
    {
        self::$hydrationInProgress = true;

        try {
            /** @phpstan-ignore-next-line */
            return new static();
        } finally {
            self::$hydrationInProgress = false;
        }
    }

    /**
     * Create a new component instance.
     *
     * The component ID is automatically generated if not provided.
     *
     * @param array<string, mixed> $initialState Initial component state
     * @param list<string> $actions List of action names this component can handle
     * @param array<string, mixed> $properties Server-supplied input properties
     */
    public function __construct(
        array $initialState = [],
        array $actions = [],
        array $properties = [],
    ) {
        $this->id = $this->generateId();
        $this->state = $initialState;
        $this->initialState = $initialState;
        $this->actions = $actions;
        $this->initializeProperties($properties);
        $this->mount();
    }

    /**
     * Generate a unique component ID.
     *
     * @return string
     */
    protected function generateId(): string
    {
        return 'ff-'.bin2hex(random_bytes(12));
    }

    /**
     * Get the component identifier.
     *
     * @return string
     */
    public function id(): string
    {
        return $this->id;
    }

    /**
     * Get the current component state.
     *
     * @return array<string, mixed>
     */
    public function state(): array
    {
        return $this->state;
    }

    /**
     * Validated immutable input properties.
     *
     * @return array<string, mixed>
     */
    public function properties(): array
    {
        return $this->properties;
    }

    public function property(string $name, mixed $default = null): mixed
    {
        return array_key_exists($name, $this->properties) ? $this->properties[$name] : $default;
    }

    /**
     * Replace immutable properties on the server.
     *
     * This is for a parent updating a child before it is rendered. Client
     * requests never call this method: properties are restored from the
     * server-side state record during hydration.
     */
    public function setProperties(array $properties): static
    {
        $this->initializeProperties($properties);

        if ($this->bootedPending) {
            $this->bootedPending = false;
            $this->hydrated = true;
            $this->booted();
        }

        return $this;
    }

    /**
     * Nested components rendered inside this component.
     *
     * @return list<Component>
     */
    public function children(): array
    {
        return [];
    }

    /**
     * Normalized URL synchronization configuration.
     *
     * Sensitive entries are omitted so trusted or secret values can never be
     * represented in browser URLs.
     *
     * @return array<string, array{param: string, type: string, multiple: bool}>
     */
    public function querySyncConfiguration(): array
    {
        $normalized = [];

        foreach ($this->querySync as $key => $definition) {
            if (!is_string($key) || $key === '' || is_string($definition) === false && !is_array($definition)) {
                continue;
            }

            $config = is_string($definition) ? ['param' => $definition] : $definition;
            $param = (string) ($config['param'] ?? $key);

            if (
                !empty($config['sensitive'])
                || preg_match('/^[A-Za-z0-9._-]{1,80}$/', $param) !== 1
            ) {
                continue;
            }

            $type = strtolower((string) ($config['type'] ?? 'string'));
            $type = match ($type) {
                'int', 'integer' => 'int',
                'bool', 'boolean' => 'bool',
                'array' => 'array',
                default => 'string',
            };

            $normalized[$key] = [
                'param' => $param,
                'type' => $type,
                'multiple' => !empty($config['multiple']) || $type === 'array',
            ];
        }

        return $normalized;
    }

    /**
     * Apply a URL-provided value after server-side type validation.
     */
    public function applyQueryValue(string $key, mixed $value): bool
    {
        $configuration = $this->querySyncConfiguration();

        if (!isset($configuration[$key])) {
            return false;
        }

        $definition = $configuration[$key];

        // A missing URL parameter resets the key to its constructor default.
        if ($value === null) {
            return $this->resetQueryValue($key);
        }

        [$valid, $validated] = $this->validateQueryValue($value, $definition);

        if (!$valid) {
            return false;
        }

        if (array_key_exists($key, $this->state)) {
            $this->updateState([$key => $validated]);

            return true;
        }

        if (array_key_exists($key, $this->propertyDefinitions)) {
            $properties = $this->properties;
            $properties[$key] = $validated;
            $this->setProperties($properties);

            return true;
        }

        return false;
    }

    /**
     * Apply URL-synchronized values submitted by the browser's built-in
     * `firefiberQuery` action (back/forward navigation).
     *
     * Only keys declared in the query-sync configuration are writable and
     * each value must pass the declared type validation; unknown keys and
     * invalid values are reported as rejected and never applied, so the
     * server keeps authority over state and the browser reconciles its URL
     * against the returned snapshot.
     *
     * @param array<string, mixed> $payload
     * @return array{applied: list<string>, rejected: list<string>}
     */
    public function applyQuerySyncPayload(array $payload): array
    {
        $applied = [];
        $rejected = [];

        foreach ($payload as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }

            if ($this->applyQueryValue($key, $value)) {
                $applied[] = $key;
                continue;
            }

            $rejected[] = $key;
        }

        return ['applied' => $applied, 'rejected' => $rejected];
    }

    /**
     * Reset a URL-synchronized key to its constructor/property default.
     */
    private function resetQueryValue(string $key): bool
    {
        if (array_key_exists($key, $this->state)) {
            $this->updateState([$key => $this->initialState[$key] ?? null]);

            return true;
        }

        if (array_key_exists($key, $this->propertyDefinitions)) {
            $properties = $this->properties;
            $properties[$key] = $this->propertyDefinitions[$key]['default'] ?? null;
            $this->setProperties($properties);

            return true;
        }

        return false;
    }

    /**
     * @param array{multiple: bool, type: string} $definition
     * @return array{0: bool, 1: mixed} valid flag and normalized value
     */
    private function validateQueryValue(mixed $value, array $definition): array
    {
        if ($definition['multiple']) {
            if (!is_array($value)) {
                $value = [$value];
            }

            $normalized = [];

            foreach ($value as $item) {
                [$valid, $scalar] = $this->normalizeQueryScalar($item, $definition['type']);

                if (!$valid) {
                    return [false, null];
                }

                $normalized[] = $scalar;
            }

            return [true, $normalized];
        }

        return $this->normalizeQueryScalar($value, $definition['type']);
    }

    /**
     * @return array{0: bool, 1: mixed} valid flag and normalized value
     */
    private function normalizeQueryScalar(mixed $value, string $type): array
    {
        return match ($type) {
            'int' => is_numeric($value) && (float) $value === (float) (int) $value
                ? [true, (int) $value]
                : [false, null],
            'bool' => match (true) {
                $value === true, $value === 1, $value === '1', $value === 'true' => [true, true],
                $value === false, $value === 0, $value === '0', $value === 'false' => [true, false],
                default => [false, null],
            },
            default => is_scalar($value) || $value === null
                ? [true, (string) ($value ?? '')]
                : [false, null],
        };
    }

    public function lazyConfiguration(): array
    {
        if (!$this->lazy) {
            return [];
        }

        return [
            'trigger' => in_array(strtolower($this->lazyTrigger), ['visible', 'manual'], true)
                ? strtolower($this->lazyTrigger)
                : 'visible',
            'placeholder' => $this->lazyPlaceholder,
            'action' => self::LOAD_ACTION,
        ];
    }

    public function optimisticActions(): array
    {
        return $this->optimisticActions;
    }

    public function uploadDefinitions(): array
    {
        return $this->uploadDefinitions;
    }

    public function cacheConfiguration(): array
    {
        return $this->cacheConfiguration;
    }

    public function rerenderOnAction(): bool
    {
        return $this->rerenderOnAction === true;
    }

    /** @return array{intervalMs: int, pausedWhenHidden: bool} */
    public function pollingConfiguration(): array
    {
        $interval = max(0, (int) $this->pollIntervalMs);

        return [
            'intervalMs' => $interval,
            'pausedWhenHidden' => $this->pollPausedWhenHidden !== false,
        ];
    }

    /**
     * Deferred work executed by the built-in lazy-load action.
     *
     * Only invoked for components that opted into lazy rendering, after
     * {@see authorize()} succeeds. The browser cannot reach arbitrary
     * methods through a lazy-loading request.
     */
    public function load(): void
    {
    }

    /**
     * Server-side work executed on each polling tick.
     *
     * Return false (or override {@see shouldContinuePolling()}) to stop the
     * browser's polling loop.
     */
    public function poll(): void
    {
    }

    /** Whether the browser should keep polling after the latest tick. */
    public function shouldContinuePolling(): bool
    {
        return true;
    }

    /**
     * Trusted server-side metadata restored from the state record.
     *
     * @return array<string, mixed>
     */
    public function trustedMetadata(): array
    {
        return $this->trustedMetadata;
    }

    public function setTrustedMetadata(array $metadata): static
    {
        $this->trustedMetadata = $metadata;

        return $this;
    }

    /**
     * Server-side storage envelope.
     *
     * The browser snapshot exposes only {@see state()}; properties and the
     * session/user/class binding metadata remain in the authoritative state
     * record.
     */
    public function storageEnvelope(): array
    {
        return [
            '__firefiber' => [
                'state' => $this->state,
                'properties' => $this->properties,
                'metadata' => $this->trustedMetadata,
            ],
        ];
    }

    /**
     * Hydrate this component from authoritative server-side state.
     *
     * This is intentionally separate from the constructor: browser-provided
     * state must never be able to choose the component ID or replace stored
     * state directly.
     *
     * @param array<string, mixed> $state
     *
     * @throws InvalidStateException When the stored component ID is invalid
     */
    public function hydrate(string $id, array $state): static
    {
        if ($id === '') {
            throw new InvalidStateException('Cannot hydrate a component with an empty identifier');
        }

        $envelope = $state['__firefiber'] ?? null;
        $properties = null;

        if (is_array($envelope) && is_array($envelope['state'] ?? null)) {
            $properties = is_array($envelope['properties'] ?? null) ? $envelope['properties'] : [];
            $this->trustedMetadata = is_array($envelope['metadata'] ?? null) ? $envelope['metadata'] : [];
            $state = $envelope['state'];
        } elseif (array_key_exists('__firefiber_properties', $state) || array_key_exists('__firefiber_metadata', $state)) {
            $properties = is_array($state['__firefiber_properties'] ?? null) ? $state['__firefiber_properties'] : [];
            $this->trustedMetadata = is_array($state['__firefiber_metadata'] ?? null) ? $state['__firefiber_metadata'] : [];
            unset($state['__firefiber_properties'], $state['__firefiber_metadata']);
        }

        $this->id = $id;

        if ($properties !== null) {
            $this->initializeProperties($properties);
        } elseif ($this->hasRequiredPropertyDefinitions()) {
            self::$hydrationInProgress = true;

            try {
                $this->initializeProperties([]);
            } finally {
                self::$hydrationInProgress = false;
            }
        } else {
            $this->initializeProperties([]);
        }

        $this->state = $state;

        if ($properties === null && $this->hasRequiredPropertyDefinitions()) {
            $this->hydrated = true;
            $this->bootedPending = true;
        } elseif (!$this->hydrated) {
            $this->hydrated = true;
            $this->booted();
        }

        return $this;
    }

    private function hasRequiredPropertyDefinitions(): bool
    {
        foreach ($this->propertyDefinitions as $definition) {
            if (is_array($definition) && !empty($definition['required'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate and normalize declared properties.
     */
    private function initializeProperties(array $provided): void
    {
        $normalized = [];

        foreach ($this->propertyDefinitions as $name => $definition) {
            if (!is_string($name) || $name === '' || !is_array($definition)) {
                throw new InvalidStateException(sprintf(
                    'Invalid property definition on %s.',
                    static::class,
                ));
            }

            if (array_key_exists($name, $provided)) {
                $value = $provided[$name];
            } elseif (array_key_exists('default', $definition)) {
                $value = $definition['default'];
            } elseif (!empty($definition['required']) && !self::$hydrationInProgress) {
                throw new InvalidStateException(sprintf(
                    'Missing required property [%s] for component %s.',
                    $name,
                    static::class,
                ));
            } else {
                $normalized[$name] = null;
                continue;
            }

            $normalized[$name] = $this->validateProperty($name, $value, $definition);
        }

        foreach ($provided as $name => $value) {
            if ($this->propertyDefinitions !== [] && !array_key_exists($name, $this->propertyDefinitions)) {
                throw new InvalidStateException(sprintf(
                    'Unknown property [%s] for component %s.',
                    (string) $name,
                    static::class,
                ));
            }

            if (!array_key_exists($name, $normalized)) {
                $normalized[$name] = $value;
            }
        }

        $this->properties = $normalized;
    }

    /**
     * @param array{type?: string, required?: bool, nullable?: bool, min?: mixed, max?: mixed, length?: string, in?: list<mixed>} $definition
     */
    private function validateProperty(string $name, mixed $value, array $definition): mixed
    {
        if ($value === null && !empty($definition['nullable'])) {
            return null;
        }

        $type = strtolower((string) ($definition['type'] ?? ''));
        $matches = match ($type) {
            '', 'mixed' => true,
            'string' => is_string($value),
            'int', 'integer' => is_int($value),
            'number', 'float', 'double' => is_int($value) || is_float($value),
            'bool', 'boolean' => is_bool($value),
            'array' => is_array($value),
            default => throw new InvalidStateException(sprintf(
                'Unsupported type [%s] for property [%s] on %s.',
                $type,
                $name,
                static::class,
            )),
        };

        if (!$matches) {
            throw new InvalidStateException(sprintf(
                'Property [%s] for component %s must be of type [%s].',
                $name,
                static::class,
                $type,
            ));
        }

        if (isset($definition['min']) && (is_int($value) || is_float($value)) && $value < $definition['min']) {
            throw new InvalidStateException(sprintf('Property [%s] is below its minimum value.', $name));
        }

        if (isset($definition['max']) && (is_int($value) || is_float($value)) && $value > $definition['max']) {
            throw new InvalidStateException(sprintf('Property [%s] exceeds its maximum value.', $name));
        }

        $length = $definition['length'] ?? null;
        if (is_string($length) && (is_string($value) || is_array($value))) {
            [$min, $max] = array_pad(explode(',', $length), 2, null);
            $actual = is_string($value) ? mb_strlen($value) : count($value);
            if ($min !== null && $actual < (int) $min) {
                throw new InvalidStateException(sprintf('Property [%s] is too short.', $name));
            }
            if ($max !== null && $max !== '' && $actual > (int) $max) {
                throw new InvalidStateException(sprintf('Property [%s] is too long.', $name));
            }
        }

        if (isset($definition['in']) && is_array($definition['in']) && !in_array($value, $definition['in'], true)) {
            throw new InvalidStateException(sprintf('Property [%s] is not an allowed value.', $name));
        }

        return $value;
    }

    /**
     * Update component state.
     *
     * Only keys present in $newState are updated; the rest of the state is
     * preserved. This method is called server-side before serializing the
     * state for transmission to the browser.
     *
     * @param array<string, mixed> $newState The new state to merge
     *
     * @return $this
     */
    public function updateState(array $newState): static
    {
        $this->state = array_merge($this->state, $newState);

        return $this;
    }

    /**
     * Initial construction hook.
     *
     * Called once after state and properties have been initialized. This is
     * the place for defaults that do not depend on storage.
     */
    protected function mount(): void
    {
    }

    /**
     * Post-hydration hook.
     *
     * Called once per component instance after trusted server-side state and
     * properties have been restored. It is not called for freshly mounted
     * components; fresh initialization is covered by {@see mount()}.
     */
    protected function booted(): void
    {
    }

    /**
     * Hook called by the action middleware before the requested action.
     */
    protected function updating(string $action, array $payload): void
    {
    }

    /** @internal Framework lifecycle bridge. */
    public function callUpdating(string $action, array $payload): void
    {
        $this->updating($action, $payload);
    }

    /**
     * Hook called after an action returns successfully.
     */
    protected function updated(string $action, array $payload, array $result): void
    {
    }

    /** @internal Framework lifecycle bridge. */
    public function callUpdated(string $action, array $payload, array $result): void
    {
        $this->updated($action, $payload, $result);
    }

    /**
     * Hook called immediately before {@see render()}.
     */
    public function rendering(): void
    {
    }

    /**
     * Cleanup hook used when a component is explicitly destroyed.
     */
    protected function destroyed(): void
    {
    }

    /**
     * Explicitly destroy this server-side component instance.
     *
     * This is an application-level cleanup hook, not a browser action. It
     * does not delete server-held state; TTL and explicit removal remain
     * application policy.
     */
    public function destroy(): void
    {
        $this->destroyed();
    }

    /**
     * Authorization gate called before every action.
     */
    public function authorize(?AuthenticatableContract $user, ?string $action = null): bool
    {
        return true;
    }

    /**
     * Queue an event for the middleware to deliver after the action succeeds.
     */
    public function emit(string $event, array $payload = []): static
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9._:-]*$/', $event) !== 1) {
            throw new InvalidStateException(sprintf('Invalid component event name [%s].', $event));
        }

        if ($this->emits !== [] && !in_array($event, $this->emits, true)) {
            throw new UnknownActionException(sprintf('Unknown component event: %s', $event), $this->id());
        }

        $payload = $this->validatedEventPayload($event, $payload);
        $this->queuedEvents[] = ['name' => $event, 'payload' => $payload];

        return $this;
    }

    /**
     * @return list<array{name: string, payload: array}>
     */
    public function queuedEvents(): array
    {
        return $this->queuedEvents;
    }

    public function clearQueuedEvents(): static
    {
        $this->queuedEvents = [];

        return $this;
    }

    public function hasListener(string $event): bool
    {
        $listener = $this->listeners[$event] ?? null;

        return is_string($listener) && $listener !== '' && method_exists($this, $listener);
    }

    public function handleEvent(string $event, array $payload = []): void
    {
        $listener = $this->listeners[$event] ?? null;

        if (!is_string($listener) || $listener === '' || !method_exists($this, $listener)) {
            throw new UnknownActionException(sprintf('Unknown component event: %s', $event), $this->id());
        }

        $payload = $this->validatedEventPayload($event, $payload);
        $this->validateEventPayload($event, $payload);
        $this->{$listener}($payload);
    }

    /**
     * Framework validation rules for emitted event payloads.
     *
     * Keys are event names and values are framework Validator rule maps.
     */
    protected function eventPayloadRules(): array
    {
        return [];
    }

    /**
     * Validate and normalize an event payload.
     */
    private function validatedEventPayload(string $event, array $payload): array
    {
        $rules = $this->eventPayloadRules()[$event] ?? null;

        if (!is_array($rules) || $rules === []) {
            return $payload;
        }

        $result = (new Validator($rules))->validate($payload);

        if ($result->fails()) {
            throw new ValidationException('Validation failed', $result->errors());
        }

        return $result->data();
    }

    protected function validateEventPayload(string $event, array $payload): void
    {
    }

    /**
     * Framework validation rules for this component's form fields.
     *
     * @return array<string, mixed>
     */
    protected function formRules(): array
    {
        return [];
    }

    /**
     * Custom messages in the framework Validator format.
     *
     * @return array<string, string>
     */
    protected function formMessages(): array
    {
        return [];
    }

    /**
     * Validate a full payload and return only validated values.
     *
     * The returned values are the only values that should be persisted.
     */
    public function validateForm(array $input): array
    {
        $result = (new Validator($this->formRules(), $this->formMessages()))->validate($input);

        if ($result->fails()) {
            throw new ValidationException('Validation failed', $result->errors());
        }

        return $result->data();
    }

    /**
     * Validate only submitted model fields, coercing values where the
     * framework validator defines lossless coercions.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function validatedModelPayload(array $payload): array
    {
        $rules = [];

        foreach ($payload as $field => $value) {
            if (isset($this->formRules()[$field])) {
                $rules[$field] = $this->formRules()[$field];
            }
        }

        if ($rules === []) {
            return $payload;
        }

        $result = (new Validator($rules, $this->formMessages()))->validate($payload);

        if ($result->fails()) {
            throw new ValidationException('Validation failed', $result->errors());
        }

        $validated = $result->data();

        foreach ($rules as $field => $rule) {
            if (array_key_exists($field, $validated)) {
                $payload[$field] = $validated[$field];
            }
        }

        return $payload;
    }

    /**
     * Register an action handler.
     *
     * Only actions registered via this method may be invoked from the browser.
     * This is a security measure to prevent unauthorized action execution.
     *
     * @param string $action The action name
     * @param callable $handler The handler callable. Signature: function(array $payload): array|string
     *
     * @return $this
     */
    public function registerAction(string $action, callable $handler): static
    {
        $this->actions[] = $action;

        return $this;
    }

    /**
     * Handle an action received from the browser.
     *
     * The default implementation throws an exception. Subclasses should
     * override this method or register action handlers via registerAction().
     *
     * @param string $action The action name
     * @param array $payload The action payload from the browser
     *
     * @return array{status: string, payload: array} Response data
     *
     * @throws \Sahil\Firefiber\Exceptions\UnknownActionException When the action is not registered
     * @throws \Sahil\Firefiber\Exceptions\InvalidStateException When the state is invalid
     */
    abstract public function handleAction(string $action, array $payload): array;

    /**
     * Apply a fiber:model update to the component state.
     *
     * Only keys declared in $modelable may be written. Every value is passed
     * through validateModelValue() before any state changes, so a rejected
     * payload leaves the component untouched. The method is final so a
     * subclass cannot widen this write path or redirect it to another method.
     *
     * @param array<string, mixed> $payload Field name => submitted value
     *
     * @return array{updated: array<string, mixed>}
     *
     * @throws UnknownActionException When a field is not model-bindable
     * @throws InvalidStateException When the payload is empty
     * @throws ValidationException When a submitted value fails validation
     */
    final public function updateModel(array $payload): array
    {
        if ($payload === []) {
            throw new InvalidStateException('Model update payload is empty');
        }

        $updated = [];

        foreach ($payload as $field => $value) {
            if (
                !is_string($field)
                || $field === ''
                || !in_array($field, $this->modelable, true)
            ) {
                throw new UnknownActionException(
                    'Model field is not bindable: ' . (is_string($field) ? $field : get_debug_type($field)),
                );
            }

            $this->validateModelValue($field, $value);
            $updated[$field] = $value;
        }

        $updated = $this->validatedModelPayload($updated);
        $this->updateState($updated);

        return ['updated' => $updated];
    }

    /**
     * Validate one submitted model value before it is applied.
     *
     * Subclasses override this hook to apply their existing validation rules
     * and throw ValidationException on failure.
     */
    protected function validateModelValue(string $field, mixed $value): void
    {
    }

    /**
     * Serialize component state for transmission to the browser.
     *
     * This method produces a JSON-safe representation of the component state.
     * Subclasses may override to customize serialization, but must ensure
     * that only JSON-serializable values are included.
     *
     * @return array{id: string, state: array}
     *
     * @throws JsonException When state contains non-serializable values
     */
    public function serializeState(): array
    {
        $json = json_encode([
            'id' => $this->id,
            'state' => $this->state,
        ], JSON_THROW_ON_ERROR);

        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return [
            'id' => $this->id,
            'state' => is_array($decoded['state'] ?? null) ? $decoded['state'] : [],
        ];
    }

    /**
     * Render the component's initial HTML.
     *
     * The page renderer wraps this markup in the secure Firefiber root
     * element. Components should escape state values themselves and use
     * `data-firefiber-bind` attributes for values that need browser updates.
     */
    public function render(): string
    {
        return '';
    }

    /**
     * Build the signed browser snapshot for the current state.
     *
     * @return array{componentId: string, state: array, timestamp: int, protocolVersion: string, signature: string}
     */
    public function snapshot(SnapshotIntegrity $integrity, int $timestamp, string $protocolVersion = Protocol::PROTOCOL_VERSION, ?string $sessionId = null, ?string $userId = null, ?string $componentClass = null): array
    {
        $snapshot = [
            'componentId' => $this->id,
            'state' => $this->state,
            'timestamp' => $timestamp,
            'protocolVersion' => $protocolVersion,
        ];

        if ($componentClass !== null && $componentClass !== '') {
            $snapshot['componentClass'] = $componentClass;
        }

        $snapshot['signature'] = $integrity->signSnapshot(
            $this->id,
            $this->state,
            $timestamp,
            $protocolVersion,
            $sessionId,
            $userId,
            $componentClass,
        );

        return $snapshot;
    }

    /**
     * Get the list of registered action names.
     *
     * @return list<string>
     */
    public function registeredActions(): array
    {
        return $this->actions;
    }

    /**
     * Validate that a submitted action is registered.
     *
     * @param string $action The action name to validate
     *
     * @return bool
     */
    protected function isActionRegistered(string $action): bool
    {
        return in_array($action, $this->actions, true);
    }
}
