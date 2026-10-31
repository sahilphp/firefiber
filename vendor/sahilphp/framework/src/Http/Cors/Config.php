<?php

declare(strict_types=1);

namespace Sahil\Http\Cors;

use Sahil\Http\Request;

/**
 * Safe CORS configuration.
 *
 * CORS is an HTTP header negotiation, not an authentication mechanism:
 * it tells browsers which origins may read responses, and it never
 * replaces tokens, sessions or authorization checks.
 *
 * Security posture:
 * - `allowed_origins` defaults to `['*']` only when `enabled` is false.
 *   When enabled it must be an explicit list; a bare `['*']` allows any
 *   origin to read credentials-shared responses, so `allow_credentials`
 *   must stay false unless the list is explicit.
 * - `allow_credentials` must stay false unless the origin list is
 *   explicit. Credentials-sharing + wildcard origin is rejected.
 */
final class Config
{
    public function __construct(
        private readonly bool $enabled,
        private readonly array $allowedOrigins,
        private readonly array $allowedMethods,
        private readonly array $allowedHeaders,
        private readonly array $exposedHeaders,
        private readonly int $maxAge,
        private readonly bool $allowCredentials,
    ) {
    }

    public static function fromArray(array $config): self
    {
        $config = array_merge([
            'enabled' => false,
            'allowed_origins' => ['*'],
            'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
            'allowed_headers' => ['Content-Type', 'Authorization', 'X-Request-Id'],
            'exposed_headers' => [],
            'max_age' => 600,
            'allow_credentials' => false,
        ], $config);

        $enabled = (bool) ($config['enabled'] ?? false);
        $allowedOrigins = (array) ($config['allowed_origins'] ?? ['*']);
        $allowCredentials = (bool) ($config['allow_credentials'] ?? false);

        if ($enabled && self::isWildcardWithCredentials($allowedOrigins, $allowCredentials)) {
            throw new \InvalidArgumentException('CORS: cannot enable credentials sharing with a wildcard origin.');
        }

        if ($enabled && self::isWildcard($allowedOrigins)) {
            throw new \InvalidArgumentException('CORS: explicit origin list is required when enabled.');
        }

        return new self(
            enabled: $enabled,
            allowedOrigins: $allowedOrigins,
            allowedMethods: (array) ($config['allowed_methods'] ?? ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']),
            allowedHeaders: (array) ($config['allowed_headers'] ?? ['Content-Type', 'Authorization', 'X-Request-Id']),
            exposedHeaders: (array) ($config['exposed_headers'] ?? []),
            maxAge: (int) ($config['max_age'] ?? 600),
            allowCredentials: (bool) ($config['allow_credentials'] ?? false),
        );
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Whether CORS applies to the incoming request.
     *
     * Only requests that carry an `Origin` header may be answered by a
     * CORS header. Requests without an Origin are not cross-origin and
     * need no negotiation.
     */
    public function shouldApply(Request $request): bool
    {
        if (!$this->enabled) {
            return false;
        }

        $origin = $request->header('Origin');

        if ($origin === null) {
            return false;
        }

        return $this->isWildcard($this->allowedOrigins) || in_array($origin, $this->allowedOrigins, true);
    }

    /** @return list<string> */
    public function allowedOrigins(): array
    {
        return $this->allowedOrigins;
    }

    public function allowedMethods(): string
    {
        return implode(', ', $this->allowedMethods);
    }

    public function allowedHeaders(): string
    {
        return implode(', ', $this->allowedHeaders);
    }

    public function exposedHeaders(): string
    {
        return implode(', ', $this->exposedHeaders);
    }

    public function maxAge(): int
    {
        return $this->maxAge;
    }

    public function allowCredentials(): bool
    {
        return $this->allowCredentials;
    }

    private static function isWildcard(array $origins): bool
    {
        return isset($origins[0]) && $origins[0] === '*';
    }

    private static function isWildcardWithCredentials(array $origins, bool $allowCredentials): bool
    {
        if (!self::isWildcard($origins)) {
            return false;
        }

        return $allowCredentials;
    }
}
