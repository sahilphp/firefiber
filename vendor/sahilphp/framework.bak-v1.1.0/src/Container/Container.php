<?php

declare(strict_types=1);

namespace Sahil\Container;

use Closure;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use Sahil\Contracts\Container\ScopeContract;
use Throwable;

/**
 * Dependency injection container.
 *
 * Supports explicit factory bindings, shared singleton instances,
 * request-scoped instances ({@see scoped()}, tied to a {@see Scope}),
 * class aliases, reflection-based auto-wiring with constructor parameter
 * injection, and cycle detection. Singletons survive the whole process;
 * scoped instances are recreated for every request and dropped when the
 * scope ends, so long-running workers never leak request state.
 */
final class Container implements ContainerInterface
{
    /** @var array<string, Closure(Container): mixed> */
    private array $bindings = [];

    /** @var array<string, bool> */
    private array $shared = [];

    /** @var array<string, bool> */
    private array $scopedBindings = [];

    /** @var array<string, object> */
    private array $instances = [];

    /** @var array<string, array<string, object>> scope id → resolved scoped instances */
    private array $scopeInstances = [];

    /** @var list<ScopeContract> active scopes, outermost first */
    private array $activeScopes = [];

    /** @var array<string, string> */
    private array $aliases = [];

    /** @var array<string, array<string, true>> */
    private array $buildStack = [];

    /**
     * Register a factory binding. A Closure factory receives this container
     * and returns the service; a string concrete names the implementing
     * class. Passing `null` (or omitting the concrete) requests auto-wiring.
     *
     * @param Closure(Container): mixed|string|null $concrete
     */
    public function bind(string $abstract, Closure|string|null $concrete = null): void
    {
        $concrete ??= $abstract;

        unset(
            $this->bindings[$abstract],
            $this->instances[$abstract],
            $this->shared[$abstract],
            $this->aliases[$abstract],
        );

        // Aliases that point at the replaced abstract must be re-registered.
        foreach ($this->aliases as $aliasKey => $target) {
            if ($target === $abstract) {
                unset($this->aliases[$aliasKey]);
            }
        }

        $this->bindings[$abstract] = $concrete === $abstract
            ? static fn (Container $container): object => $container->build($abstract)
            : (is_object($concrete)
                ? static fn (Container $container): mixed => $concrete($container)
                : static fn (Container $container): object => $container->make($concrete));
    }

    /**
     * Register a binding like {@see bind()} unless the abstract is already
     * registered (as a binding factory or a shared instance).
     *
     * @param Closure(Container): mixed|string|null $concrete
     */
    public function singleton(string $abstract, Closure|string|null $concrete = null): void
    {
        $this->bind($abstract, $concrete);
        $this->shared[$abstract] = true;
    }

    /**
     * Bind like {@see bind()} unless the abstract is already registered.
     *
     * @param Closure(Container): mixed|string|null $concrete
     */
    public function bindIf(string $abstract, Closure|string|null $concrete = null): void
    {
        if (!isset($this->bindings[$abstract]) && !isset($this->instances[$abstract])) {
            $this->bind($abstract, $concrete);
        }
    }

    /**
     * Register a binding resolved once per request scope ({@see beginScope}).
     * Resolving a scoped binding outside any scope is an error: stale
     * request state must not be reachable by accident.
     */
    public function scoped(string $abstract, Closure|string|null $concrete = null): void
    {
        $this->bind($abstract, $concrete);
        unset($this->shared[$abstract]);
        $this->scopedBindings[$abstract] = true;
    }

    /**
     * Open an explicit request scope. Ends must be balanced: either call
     * {@see Scope::end()} yourself or let the caller (e.g.
     * `Application::isolatedRequest()`) do it in a `finally` block.
     */
    public function beginScope(): ScopeContract
    {
        $scope = new Scope($this);
        $this->activeScopes[] = $scope;

        return $scope;
    }

    /**
     * Drop every instance resolved inside a scope. Internal: called by
     * {@see Scope::end()}.
     */
    public function endScope(Scope $scope): void
    {
        foreach ($this->activeScopes as $index => $active) {
            if ($active === $scope) {
                array_splice($this->activeScopes, $index, 1);
            }
        }

        unset($this->scopeInstances[$scope->id()]);
    }

    /**
     * The innermost active scope, or null when none is open.
     */
    public function currentScope(): ?ScopeContract
    {
        return $this->activeScopes === [] ? null : $this->activeScopes[count($this->activeScopes) - 1];
    }

    /**
     * Whether every scope that has been opened has also been properly
     * closed. Workers assert this between iterations.
     */
    public function hasOpenScopes(): bool
    {
        return $this->activeScopes !== [];
    }

    /**
     * Register an existing object instance. Aliases are resolved to the
     * shared instance the same way real class names are.
     */
    public function instance(string $abstract, object $instance): void
    {
        unset($this->bindings[$abstract], $this->scopedBindings[$abstract]);

        $this->instances[$abstract] = $instance;
        unset($this->shared[$this->getBaseName($abstract)]);
    }

    /**
     * Register an alias for an existing binding or instance.
     */
    public function alias(string $alias, string $abstract): void
    {
        if ($alias === $abstract) {
            throw new BindingException("[$alias] cannot alias itself. Register the alias against a different abstract.");
        }

        $this->aliases[$alias] = $abstract;
    }

    public function has(string $id): bool
    {
        return $this->bound($id) || class_exists($id);
    }

    public function bound(string $id): bool
    {
        $id = $this->getBaseName($id);

        return isset($this->bindings[$id]) || isset($this->instances[$id]);
    }

    public function get(string $id): mixed
    {
        return $this->resolve($id);
    }

    /**
     * Resolve a service. Unlike {@see get()} this never returns a value for
     * unresolvable identifiers: it throws instead (except for optional
     * constructor parameters, which fall back to their declared defaults).
     */
    public function make(string $abstract): object
    {
        $resolved = $this->resolve($abstract);

        if (!is_object($resolved)) {
            throw new BindingException(
                sprintf('Binding for [%s] produced a non-object value.', $abstract),
            );
        }

        return $resolved;
    }

    /**
     * Drop every binding, instance and alias created since construction.
     * Called by long-running workers between requests. Bindings created
     * during boot are expected to be re-registered by the application.
     */
    public function flush(): void
    {
        $this->bindings = [];
        $this->shared = [];
        $this->scopedBindings = [];
        $this->instances = [];
        $this->scopeInstances = [];
        $this->activeScopes = [];
        $this->aliases = [];
    }

    /**
     * Instantiate a concrete class by wiring constructor parameters.
     */
    private function build(string $class): object
    {
        if (isset($this->buildStack[$class])) {
            $chain = implode(' -> ', [...array_keys($this->buildStack), $class]);

            throw new BindingException(
                sprintf('Circular dependency detected: %s. ', $chain).
                'Break the cycle with lazy resolution (closures, setters or explicit bindings).',
            );
        }

        if (!class_exists($class)) {
            throw new NotFoundException(
                sprintf('No class named [%s] exists and no binding is registered for it. ', $class).
                'Register one with bind() or singleton() first.',
            );
        }

        $reflection = new ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            throw new BindingException(
                sprintf('Class [%s] is not instantiable. Resolve it with a factory binding instead.', $class),
            );
        }

        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return new $class();
        }

        $this->buildStack[$class] = true;

        try {
            $arguments = array_map(
                fn (ReflectionParameter $parameter): mixed => $this->resolveParameter($parameter, $class),
                $constructor->getParameters(),
            );
        } finally {
            unset($this->buildStack[$class]);
        }

        try {
            return $reflection->newInstanceArgs($arguments);
        } catch (Throwable $exception) {
            throw new BindingException(
                sprintf('Could not instantiate [%s]: %s', $class, $exception->getMessage()),
                previous: $exception,
            );
        }
    }

    private function resolveParameter(ReflectionParameter $parameter, string $concrete): mixed
    {
        $type = $parameter->getType();

        if ($type === null) {
            // Untyped parameters cannot be inferred; require a type hint
            // or a default value.
            throw new BindingException(
                sprintf(
                    'Unresolvable untyped parameter $%s while building [%s]. Give it a type-hinted dependency or a default value.',
                    $parameter->getName(),
                    $concrete,
                ),
            );
        }

        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            return $this->make($type->getName());
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        if ($parameter->allowsNull()) {
            return null;
        }

        throw new BindingException(
            sprintf(
                'Unresolvable parameter $%s while building [%s]. Give it a type-hinted dependency, a default value or nullable type.',
                $parameter->getName(),
                $concrete,
            ),
        );
    }

    private function resolve(string $abstract): mixed
    {
        $base = $this->getBaseName($abstract);

        if (isset($this->instances[$base])) {
            return $this->instances[$base];
        }

        if (isset($this->scopedBindings[$base])) {
            $scope = $this->currentScope();

            if ($scope === null) {
                throw new BindingException(
                    sprintf(
                        'Binding [%s] is request-scoped but no scope is active. Open a scope with beginScope() (or run through Application::isolatedRequest()) before resolving it.',
                        $abstract,
                    ),
                );
            }

            if (isset($this->scopeInstances[$scope->id()][$base])) {
                return $this->scopeInstances[$scope->id()][$base];
            }

            $object = $this->bindings[$base]($this);

            if (!is_object($object)) {
                throw new BindingException(
                    sprintf('Scoped binding [%s] produced a non-object value; it cannot represent a request service.', $abstract),
                );
            }

            $this->scopeInstances[$scope->id()][$base] = $object;

            return $object;
        }

        if (isset($this->bindings[$base])) {
            $object = ($this->bindings[$base])($this);

            if (($this->shared[$base] ?? false) && is_object($object)) {
                $this->instances[$base] = $object;
            }

            return $object;
        }

        if (class_exists($base)) {
            return $this->build($base);
        }

        throw new NotFoundException(sprintf('Identifier [%s] is not bound and cannot be auto-wired.', $abstract));
    }

    /**
     * Follow the alias chain down to the concrete identifier.
     */
    private function getBaseName(string $abstract): string
    {
        $seen = [];

        while (isset($this->aliases[$abstract])) {
            if (isset($seen[$abstract])) {
                throw new BindingException(sprintf('Alias chain loop detected for [%s].', $abstract));
            }

            $seen[$abstract] = true;
            $abstract = $this->aliases[$abstract];
        }

        return $abstract;
    }
}
