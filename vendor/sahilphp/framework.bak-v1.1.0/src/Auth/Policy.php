<?php

declare(strict_types=1);

namespace Sahil\Auth;

use Sahil\Contracts\Auth\AuthenticatableContract;

/**
 * Base class for authorization policies.
 *
 * Policies provide object-oriented authorization logic. Each policy class
 * corresponds to a model type and defines methods for various actions.
 *
 * Example:
 *     class PostPolicy extends Policy
 *     {
 *         public function view(AuthenticatableContract $user, Post $post): bool
 *         {
 *             return $post->isPublished() || $user->id === $post->author_id;
 *         }
 *
 *         public function update(AuthenticatableContract $user, Post $post): bool
 *         {
 *             return $user->id === $post->author_id;
 *         }
 *     }
 */
abstract class Policy
{
    /**
     * Determine if the user can view the model.
     */
    public function view(AuthenticatableContract $user, mixed $model): bool
    {
        return false;
    }

    /**
     * Determine if the user can create the model.
     */
    public function create(AuthenticatableContract $user): bool
    {
        return false;
    }

    /**
     * Determine if the user can update the model.
     */
    public function update(AuthenticatableContract $user, mixed $model): bool
    {
        return false;
    }

    /**
     * Determine if the user can delete the model.
     */
    public function delete(AuthenticatableContract $user, mixed $model): bool
    {
        return false;
    }

    /**
     * Determine if the user can restore the model.
     */
    public function restore(AuthenticatableContract $user, mixed $model): bool
    {
        return false;
    }

    /**
     * Determine if the user can force delete the model.
     */
    public function forceDelete(AuthenticatableContract $user, mixed $model): bool
    {
        return false;
    }
}
