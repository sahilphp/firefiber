<?php

declare(strict_types=1);

namespace Sahil\Auth;

use Sahil\Contracts\Auth\AuthenticatableContract;

/**
 * Simple user implementation for database records.
 *
 * This is a basic implementation that wraps database rows.
 * Applications should create their own user models that
 * implement AuthenticatableContract.
 */
final class SimpleUser implements AuthenticatableContract
{
    /** @param array<string, mixed> $attributes */
    public function __construct(
        private readonly array $attributes,
    ) {
    }

    public function getAuthIdentifier(): int|string
    {
        return $this->attributes['id'];
    }

    public function getAuthPassword(): string
    {
        return $this->attributes['password'];
    }

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    /**
     * Get an attribute value.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * Check if an attribute exists.
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->attributes);
    }
}
