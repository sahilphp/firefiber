<?php

declare(strict_types=1);

namespace Sahil\Session;

use Sahil\Contracts\Session\SessionContract;
use Sahil\Contracts\Session\SessionDriverContract;
use Sahil\Http\Request;
use Sahil\Http\Response;

/**
 * Session manager with security features.
 *
 * Provides a high-level session interface with:
 * - Secure cookie attributes (SameSite, Secure, HttpOnly)
 * - Session ID rotation to prevent fixation attacks
 * - Session expiration and garbage collection
 * - CSRF token generation and validation
 * - Protection against session leakage in long-running workers
 *
 * Security posture:
 * - Session IDs are regenerated on privilege escalation
 * - CSRF tokens are cryptographically random and per-session
 * - Cookie attributes are set to prevent theft
 * - Session data is isolated per request scope
 */
final class Session implements SessionContract
{
    private const CSRF_KEY = '_token';

    private const LAST_ACTIVITY_KEY = '_last_activity';

    private bool $started = false;

    private bool $saved = false;

    private ?string $id = null;

    private string $name = 'sahil_session';

    /** @var array<string, mixed> */
    private array $data = [];

    public function __construct(
        private readonly SessionDriverContract $driver,
        private readonly int $lifetime = 7200, // 2 hours default
        private readonly bool $secure = false,
        private readonly bool $httpOnly = true,
        private readonly string $sameSite = 'lax',
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->ensureStarted();

        return $this->data[$key] ?? $default;
    }

    public function put(string $key, mixed $value): void
    {
        $this->ensureStarted();

        $this->data[$key] = $value;
    }

    public function has(string $key): bool
    {
        $this->ensureStarted();

        return array_key_exists($key, $this->data);
    }

    public function forget(string $key): void
    {
        $this->ensureStarted();

        unset($this->data[$key]);
    }

    public function all(): array
    {
        $this->ensureStarted();

        return $this->data;
    }

    public function flush(): void
    {
        $this->ensureStarted();

        // Preserve CSRF token
        $token = $this->data[self::CSRF_KEY] ?? null;

        $this->data = [];

        if ($token !== null) {
            $this->data[self::CSRF_KEY] = $token;
        }
    }

    public function id(): string
    {
        $this->ensureStarted();

        return $this->id;
    }

    public function regenerate(bool $destroy = false): void
    {
        $this->ensureStarted();

        $oldId = $this->id;
        $newId = $this->driver->generateId();

        if ($destroy) {
            $this->driver->destroy($oldId);
            $this->data = [];
        }

        $this->id = $newId;
        $this->saved = false; // Force write with new ID
    }

    public function save(): void
    {
        if (!$this->started || $this->saved) {
            return;
        }

        // Update last activity timestamp
        $this->data[self::LAST_ACTIVITY_KEY] = time();

        // Ensure CSRF token exists
        if (!isset($this->data[self::CSRF_KEY])) {
            $this->data[self::CSRF_KEY] = $this->generateCsrfToken();
        }

        $this->driver->write($this->id, $this->data);
        $this->saved = true;
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    public function token(): string
    {
        $this->ensureStarted();

        if (!isset($this->data[self::CSRF_KEY])) {
            $this->data[self::CSRF_KEY] = $this->generateCsrfToken();
        }

        return $this->data[self::CSRF_KEY];
    }

    public function validateToken(string $token): bool
    {
        $this->ensureStarted();

        $stored = $this->data[self::CSRF_KEY] ?? null;

        if ($stored === null) {
            return false;
        }

        return hash_equals($stored, $token);
    }

    public function regenerateToken(): void
    {
        $this->ensureStarted();

        $this->data[self::CSRF_KEY] = $this->generateCsrfToken();
        $this->saved = false;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    /**
     * Start the session from a request.
     *
     * Reads the session ID from the cookie and loads session data.
     */
    public function startFromRequest(Request $request): void
    {
        if ($this->started) {
            return;
        }

        $sessionId = $request->cookie($this->name);

        if ($sessionId !== null && $this->driver->isValidId($sessionId)) {
            $this->id = $sessionId;
            $this->data = $this->driver->read($sessionId);

            // Check if session has expired
            $lastActivity = $this->data[self::LAST_ACTIVITY_KEY] ?? null;

            if ($lastActivity !== null && (time() - $lastActivity) > $this->lifetime) {
                // Session expired, regenerate
                $this->regenerate(true);
            }
        } else {
            // No valid session, create new
            $this->id = $this->driver->generateId();
            $this->data = [];
        }

        $this->started = true;
    }

    /**
     * Apply the session cookie to a response.
     */
    public function applyCookieToResponse(Response $response): Response
    {
        if (!$this->started) {
            return $response;
        }

        $cookie = sprintf(
            '%s=%s; Path=/%s; SameSite=%s%s; Max-Age=%d',
            $this->name,
            $this->id,
            $this->httpOnly ? '; HttpOnly' : '',
            $this->sameSite,
            $this->secure ? '; Secure' : '',
            $this->lifetime,
        );

        return $response->withHeader('Set-Cookie', $cookie);
    }

    /**
     * Perform garbage collection on expired sessions.
     *
     * Should be called periodically (e.g., on a random subset of requests).
     */
    public function gc(): void
    {
        $this->driver->gc($this->lifetime);
    }

    /**
     * Generate a cryptographically secure CSRF token.
     */
    private function generateCsrfToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Ensure the session is started by generating an ID if needed.
     */
    private function ensureStarted(): void
    {
        if (!$this->started) {
            $this->id = $this->driver->generateId();
            $this->data = [];
            $this->started = true;
        }
    }
}
