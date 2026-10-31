<?php

declare(strict_types=1);

namespace Sahil\Firefiber;

use JsonException;
use Sahil\Contracts\Foundation\ApplicationContract;
use Sahil\Firefiber\Exceptions\InvalidStateException;
use Sahil\Firefiber\Exceptions\UnknownActionException;
use Sahil\Firefiber\Exceptions\ValidationException;

/**
 * Base class for Firefiber components.
 *
 * Components are the fundamental reactive units in Firefiber. Each component
 * manages its own state and handles actions received from the browser.
 *
 * @template TState of array{
 *     [string, mixed]
 * }
 *
 * @extends ComponentBase<TState>
 */
abstract class Component
{
    /** @used-by subclasses */
    public const string COMPONENT_CLASS = 'firefiber-component';

    /** Action name used by the browser runtime's fiber:model directive. */
    public const string MODEL_ACTION = 'updateModel';

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
     * Create a new component instance.
     *
     * The component ID is automatically generated if not provided.
     *
     * @param array<string, mixed> $initialState Initial component state
     * @param list<string> $actions List of action names this component can handle
     */
    public function __construct(
        array $initialState = [],
        array $actions = [],
    ) {
        $this->id = $this->generateId();
        $this->state = $initialState;
        $this->actions = $actions;
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

        $this->id = $id;
        $this->state = $state;

        return $this;
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

        return [
            'id' => $this->id,
            'state' => json_decode($json, true, 512, JSON_THROW_ON_ERROR),
        ];
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