<?php

declare(strict_types=1);

namespace Sahil\Contracts\Auth;

/**
 * Contract for authorizable user models.
 *
 * Users that can be authorized must implement this interface to provide
 * their roles and permissions.
 */
interface AuthorizableContract
{
    /**
     * Check if the user has a specific role.
     */
    public function hasRole(string $role): bool;

    /**
     * Check if the user has a specific permission.
     */
    public function hasPermission(string $permission): bool;

    /**
     * Get all roles for the user.
     *
     * @return list<string>
     */
    public function getRoles(): array;

    /**
     * Get all permissions for the user.
     *
     * @return list<string>
     */
    public function getPermissions(): array;
}
