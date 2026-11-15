<?php

declare(strict_types=1);

namespace Sahil\Http\Middleware;

use Closure;
use Sahil\Contracts\Session\SessionContract;
use Sahil\Http\Request;
use Sahil\Http\Response;

/**
 * Session management middleware.
 *
 * Starts the session from the request cookie, ensures the session is saved
 * at the end of the request lifecycle, and applies the session cookie to
 * the response.
 *
 * This middleware also performs random garbage collection (1% chance) to
 * clean up expired sessions without impacting performance.
 *
 * Security posture:
 * - Sessions are isolated per request scope
 * - Session data is saved at the end of the request lifecycle
 * - Garbage collection runs probabilistically to avoid performance impact
 * - Cookie attributes are applied to prevent session theft
 */
final class StartSession implements MiddlewareContract
{
    public function __construct(
        private readonly SessionContract $session,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        // Start the session from the request
        $this->session->startFromRequest($request);

        // Process the request
        $response = $next($request);

        // Save the session data
        $this->session->save();

        // Apply session cookie to response
        $response = $this->session->applyCookieToResponse($response);

        // Random garbage collection (1% chance)
        if (random_int(1, 100) === 1) {
            $this->session->gc();
        }

        return $response;
    }
}
