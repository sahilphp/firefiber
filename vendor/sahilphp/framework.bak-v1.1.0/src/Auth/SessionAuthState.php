<?php

declare(strict_types=1);

namespace Sahil\Auth;

use Sahil\Contracts\Auth\AuthenticatableContract;
use Sahil\Contracts\Auth\AuthStateContract;
use Sahil\Contracts\Auth\UserProviderContract;
use Sahil\Contracts\Session\SessionContract;

/**
 * Session-based authentication state.
 *
 * Stores the authenticated user ID in the session and retrieves
 * the user from the provider on each request.
 *
 * Security features:
 * - Session fixation protection (regenerates session ID on login)
 * - Stores only user ID in session (not sensitive data)
 * - Supports "remember me" functionality via tokens
 */
final class SessionAuthState implements AuthStateContract
{
    private const SESSION_KEY = '_auth_user_id';

    private const REMEMBER_KEY = '_auth_remember_token';

    public function __construct(
        private readonly SessionContract $session,
        private readonly UserProviderContract $provider,
    ) {
    }

    public function user(): ?AuthenticatableContract
    {
        $id = $this->id();

        if ($id === null) {
            return null;
        }

        return $this->provider->retrieveByIdentifier($id);
    }

    public function id(): int|string|null
    {
        return $this->session->get(self::SESSION_KEY);
    }

    public function check(): bool
    {
        return $this->session->has(self::SESSION_KEY);
    }

    public function guest(): bool
    {
        return !$this->check();
    }

    public function setUser(AuthenticatableContract $user): void
    {
        // Regenerate session ID to prevent session fixation
        $this->session->regenerate(true);

        $this->session->put(self::SESSION_KEY, $user->getAuthIdentifier());
    }

    public function clear(): void
    {
        $this->session->forget(self::SESSION_KEY);
        $this->session->forget(self::REMEMBER_KEY);
        $this->session->regenerate(true);
    }

    public function remember(AuthenticatableContract $user, string $token): void
    {
        $this->session->put(self::REMEMBER_KEY, [
            'id' => $user->getAuthIdentifier(),
            'token' => $token,
        ]);
    }

    public function retrieveRememberedUser(string $token): ?AuthenticatableContract
    {
        $remembered = $this->session->get(self::REMEMBER_KEY);

        if ($remembered === null) {
            return null;
        }

        if (!hash_equals($remembered['token'], $token)) {
            return null;
        }

        return $this->provider->retrieveByIdentifier($remembered['id']);
    }

    public function forgetRememberToken(AuthenticatableContract $user): void
    {
        $this->session->forget(self::REMEMBER_KEY);
    }
}
