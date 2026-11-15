<?php

declare(strict_types=1);

namespace Sahil\Auth;

use Closure;
use Sahil\Contracts\Auth\AuthenticatableContract;

/**
 * Authorization gate system.
 *
 * Gates are simple callback-based authorization checks that can be
 * registered and evaluated throughout the application.
 *
 * Gates provide a flexible way to define authorization logic without
 * requiring full policy classes.
 */
final class Gate
{
    /** @var array<string, Closure> */
    private array $gates = [];

    /**
     * Define a gate authorization callback.
     *
     * @param Closure(AuthenticatableContract|null, mixed...): bool $callback
     */
    public function define(string $name, Closure $callback): void
    {
        $this->gates[$name] = $callback;
    }

    /**
     * Check if a gate allows access.
     *
     * @param array<mixed> $arguments
     */
    public function allows(string $name, ?AuthenticatableContract $user = null, array $arguments = []): bool
    {
        if (!isset($this->gates[$name])) {
            return false;
        }

        return $this->gates[$name]($user, ...$arguments);
    }

    /**
     * Check if a gate denies access.
     *
     * @param array<mixed> $arguments
     */
    public function denies(string $name, ?AuthenticatableContract $user = null, array $arguments = []): bool
    {
        return !$this->allows($name, $user, $arguments);
    }

    /**
     * Check if any of the given gates allow access.
     *
     * @param list<string> $abilities
     * @param array<mixed> $arguments
     */
    public function any(array $abilities, ?AuthenticatableContract $user = null, array $arguments = []): bool
    {
        foreach ($abilities as $ability) {
            if ($this->allows($ability, $user, $arguments)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if all of the given gates allow access.
     *
     * @param list<string> $abilities
     * @param array<mixed> $arguments
     */
    public function all(array $abilities, ?AuthenticatableContract $user = null, array $arguments = []): bool
    {
        foreach ($abilities as $ability) {
            if ($this->denies($ability, $user, $arguments)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if a user can perform an action.
     *
     * This is a convenience method that combines gates, roles, and permissions.
     *
     * @param array<mixed> $arguments
     */
    public function can(string $ability, ?AuthenticatableContract $user = null, array $arguments = []): bool
    {
        // Check gates first
        if ($this->allows($ability, $user, $arguments)) {
            return true;
        }

        // Check permissions if user is authorizable
        if ($user !== null && $user instanceof \Sahil\Contracts\Auth\AuthorizableContract) {
            if ($user->hasPermission($ability)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a user cannot perform an action.
     *
     * @param array<mixed> $arguments
     */
    public function cannot(string $ability, ?AuthenticatableContract $user = null, array $arguments = []): bool
    {
        return !$this->can($ability, $user, $arguments);
    }
}
