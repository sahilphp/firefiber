<?php

declare(strict_types=1);

namespace Sahil\Http\Middleware;

use Closure;
use Sahil\Contracts\Session\SessionContract;
use Sahil\Http\Exception\BadRequestHttpException;
use Sahil\Http\Request;
use Sahil\Http\Response;

/**
 * CSRF protection middleware.
 *
 * Validates CSRF tokens for state-changing HTTP methods (POST, PUT, PATCH,
 * DELETE) to prevent Cross-Site Request Forgery attacks.
 *
 * Security posture:
 * - CSRF verification is enforced for all unsafe methods by default
 * - Tokens are per-session and cryptographically random
 * - Token validation uses constant-time comparison (hash_equals)
 * - Exclusions are opt-in and must be explicitly documented
 *
 * Exclusions:
 * Use the `except` property to list route paths that should bypass CSRF
 * protection. Only add exclusions when:
 * - The endpoint is a public API with no session state
 * - The endpoint has its own authentication mechanism
 * - The endpoint is used by third-party webhooks
 *
 * Example:
 *     $middleware = new VerifyCsrfToken($session, except: ['/api/webhook']);
 */
final class VerifyCsrfToken implements MiddlewareContract
{
    /**
     * HTTP methods that require CSRF protection.
     */
    private const UNSAFE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * Default CSRF token input field name.
     */
    private const TOKEN_INPUT = '_token';

    /**
     * Default CSRF token header name.
     */
    private const TOKEN_HEADER = 'X-CSRF-TOKEN';

    /**
     * @param list<string> $except route paths to exclude from CSRF protection
     */
    public function __construct(
        private readonly SessionContract $session,
        private readonly array $except = [],
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isReading($request) || $this->inExceptArray($request)) {
            return $next($request);
        }

        if (!$this->tokensMatch($request)) {
            throw new BadRequestHttpException(
                'CSRF token validation failed.',
            );
        }

        // Regenerate token on successful validation to prevent token reuse
        $this->session->regenerateToken();

        return $next($request);
    }

    /**
     * Determine if the request is a "read" operation (safe methods).
     *
     * Anything outside the unsafe set is treated as a read; only the
     * state-changing verbs require a CSRF token.
     */
    private function isReading(Request $request): bool
    {
        return !in_array($request->method(), self::UNSAFE_METHODS, true);
    }

    /**
     * Determine if the request path is in the exclusion list.
     */
    private function inExceptArray(Request $request): bool
    {
        foreach ($this->except as $except) {
            if ($request->path() === $except) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if the CSRF tokens match.
     */
    private function tokensMatch(Request $request): bool
    {
        $token = $this->getTokenFromRequest($request);

        return is_string($token) && $this->session->validateToken($token);
    }

    /**
     * Get the CSRF token from the request.
     *
     * Checks the following locations in order:
     * 1. POST input field (default: '_token')
     * 2. X-CSRF-TOKEN header
     * 3. X-XSRF-TOKEN header (for backward compatibility)
     */
    private function getTokenFromRequest(Request $request): ?string
    {
        // Check POST input
        $form = $request->form();
        if (isset($form[self::TOKEN_INPUT])) {
            return $form[self::TOKEN_INPUT];
        }

        // Check JSON body
        if ($request->hasJsonBody()) {
            $json = $request->json();
            if (isset($json[self::TOKEN_INPUT]) && is_string($json[self::TOKEN_INPUT])) {
                return $json[self::TOKEN_INPUT];
            }
        }

        // Check X-CSRF-TOKEN header
        $header = $request->header(self::TOKEN_HEADER);
        if ($header !== null) {
            return $header;
        }

        // Check X-XSRF-TOKEN header (common in frontend frameworks)
        $xsrf = $request->header('X-XSRF-TOKEN');
        if ($xsrf !== null) {
            return $xsrf;
        }

        return null;
    }
}
