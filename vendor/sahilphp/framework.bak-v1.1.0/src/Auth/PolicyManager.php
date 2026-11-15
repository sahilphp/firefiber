<?php

declare(strict_types=1);

namespace Sahil\Auth;

use Sahil\Contracts\Auth\AuthenticatableContract;

/**
 * Policy manager for object-oriented authorization.
 *
 * Registers and evaluates policies for model authorization.
 */
final class PolicyManager
{
    /** @var array<string, class-string<Policy>> */
    private array $policies = [];

    /**
     * Register a policy for a model class.
     *
     * @param class-string $model
     * @param class-string<Policy> $policy
     */
    public function register(string $model, string $policy): void
    {
        $this->policies[$model] = $policy;
    }

    /**
     * Get the policy for a model.
     *
     * @param class-string $model
     * @return Policy|null
     */
    public function for(string $model): ?Policy
    {
        if (!isset($this->policies[$model])) {
            return null;
        }

        return new $this->policies[$model]();
    }

    /**
     * Check if a user can perform an action on a model.
     *
     * @param class-string $model
     */
    public function can(string $action, string $model, ?AuthenticatableContract $user, mixed $modelInstance = null): bool
    {
        $policy = $this->for($model);

        if ($policy === null) {
            return false;
        }

        if (!method_exists($policy, $action)) {
            return false;
        }

        if ($modelInstance === null) {
            return $policy->{$action}($user);
        }

        return $policy->{$action}($user, $modelInstance);
    }
}
