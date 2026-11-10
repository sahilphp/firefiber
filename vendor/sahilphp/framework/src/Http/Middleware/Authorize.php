<?php

declare(strict_types=1);

namespace Sahil\Http\Middleware;

use Closure;
use RuntimeException;
use Sahil\Auth\AuthManager;
use Sahil\Auth\Gate;
use Sahil\Http\Request;
use Sahil\Http\Response;

/**
 * Authorization middleware.
 *
 * Checks if the authenticated user has the required permissions,
 * roles, or gate abilities before allowing access to a route.
 *
 * Two registration forms are supported:
 *
 *     // route parameters (resolved through a MiddlewareSpec)
 *     Route::get('/posts', [PostController::class, 'store'])
 *         ->middleware([new MiddlewareSpec(Authorize::class, ['publish-post'])]);
 *
 *     // direct invocation (existing behaviour)
 *     $authorize->handle($request, $next, 'publish-post');
 *
 * The abilities this middleware checks are the *session* user's: it reads
 * `AuthManager::user()`. Bearer/token authorization is a separate concern.
 */
final class Authorize implements MiddlewareContract, ParameterizedMiddlewareContract
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
        return $this->authorize($request, $next, array_values($abilities));
    }

    /**
     * Handle a route that configured abilities through a middleware spec.
     *
     * @param array<array-key, mixed> $parameters route parameters (ability names)
     *
     * @throws RuntimeException When a parameter is not a non-empty ability name
     */
    public function handleWithParameters(Request $request, Closure $next, array $parameters): Response
    {
        foreach ($parameters as $index => $parameter) {
            if (!is_string($parameter) || trim($parameter) === '') {
                throw new RuntimeException(sprintf(
                    'Authorize middleware parameter at index %d must be a non-empty ability name; got %s.',
                    $index,
                    get_debug_type($parameter),
                ));
            }
        }

        /** @var list<string> $abilities */
        $abilities = array_values($parameters);

        return $this->authorize($request, $next, $abilities);
    }

    /**
     * @param list<string> $abilities
     */
    private function authorize(Request $request, Closure $next, array $abilities): Response
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
