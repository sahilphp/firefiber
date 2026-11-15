<?php

declare(strict_types=1);

namespace Sahil\Frontend;

/**
 * Registry of frontend adapters, keyed by the target name they handle.
 *
 * The manager is a plain object (no static state, no container lookups), so
 * adapters are registered explicitly by a service provider and resolved
 * through the dependency injection container.
 */
final class FrontendManager
{
    /** @var array<string, FrontendAdapterContract> */
    private array $adapters = [];

    /**
     * Register an adapter. Registering the same target twice is a
     * configuration error: two integrations must never silently shadow
     * each other.
     */
    public function register(FrontendAdapterContract $adapter): void
    {
        $name = $adapter->name();

        if (preg_match('/^[a-z][a-z0-9-]*$/', $name) !== 1) {
            throw new InvalidFrontendConfigurationException(sprintf(
                'Frontend adapter name [%s] must be lower-case letters, digits and dashes.',
                $name,
            ));
        }

        if (isset($this->adapters[$name])) {
            throw new InvalidFrontendConfigurationException(sprintf(
                'A frontend adapter for [%s] is already registered.',
                $name,
            ));
        }

        $this->adapters[$name] = $adapter;
    }

    public function has(string $target): bool
    {
        return isset($this->adapters[$target]);
    }

    /**
     * @throws MissingFrontendAdapterException when no adapter is registered
     */
    public function adapter(string $target): FrontendAdapterContract
    {
        return $this->adapters[$target] ?? throw MissingFrontendAdapterException::forTarget(
            $target,
            array_keys($this->adapters),
        );
    }

    /**
     * @return list<string>
     */
    public function targets(): array
    {
        $targets = array_keys($this->adapters);
        sort($targets);

        return $targets;
    }
}
