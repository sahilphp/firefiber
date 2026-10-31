<?php

declare(strict_types=1);

namespace Sahil\Http\Middleware;

use Closure;
use Sahil\Auth\AuthManager;
use Sahil\Auth\Gate;
use Sahil\Http\Request;
use Sahil\Http\Response;

/**
 * Authorization middleware.
 *
 * Checks if the authenticated user has the required permissions,
 * roles, or gate abilities before allowing access to a route.
 */
final class Authorize
{
    public function __construct(
        private readonly AuthManager $auth,
        private readonly Gate $gate,
    ) {
    }

    /**
     * Handle the incoming request.
     *
     * @param string ...$abilities gate abilities that grant access
     */
    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        $user = $this->auth->user();

        if ($user === null) {
            return $this->unauthorized($request);
        }

        // Check gate abilities
        if ($this->gate->any($abilities, $user)) {
            return $next($request);
        }

        // Check if the user has the required permissions
        if ($user instanceof \Sahil\Contracts\Auth\AuthorizableContract) {
            foreach ($abilities as $ability) {
                if ($user->hasPermission($ability)) {
                    return $next($request);
                }
            }
        }

        return $this->forbidden($request);
    }

    /**
     * Handle unauthorized requests (401).
     */
    private function unauthorized(Request $request): Response
    {
        $accept = $request->header('Accept');
        $expectsJson = $accept !== null && str_contains($accept, 'application/json');

        if ($expectsJson) {
            return Response::json([
                'error' => 'unauthorized',
                'message' => 'Authentication required.',
            ], 401);
        }

        return Response::json([
            'error' => 'unauthorized',
            'message' => 'Authentication required.',
        ], 401);
    }

    /**
     * Handle forbidden requests (403).
     */
    private function forbidden(Request $request): Response
    {
        $accept = $request->header('Accept');
        $expectsJson = $accept !== null && str_contains($accept, 'application/json');

        if ($expectsJson) {
            return Response::json([
                'error' => 'forbidden',
                'message' => 'You do not have permission to access this resource.',
            ], 403);
        }

        return Response::json([
            'error' => 'forbidden',
            'message' => 'You do not have permission to access this resource.',
        ], 403);
    }
}
