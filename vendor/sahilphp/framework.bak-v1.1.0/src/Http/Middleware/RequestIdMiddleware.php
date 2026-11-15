<?php

declare(strict_types=1);

namespace Sahil\Http\Middleware;

use Closure;
use Sahil\Http\Request;
use Sahil\Http\Response;

/**
 * Attach one server-generated request identifier to every response as
 * `X-Request-Id`.
 *
 * Two competing servers must never emit colliding identifiers, so the
 * identifier is generated once per request from a fresh 128-bit value and
 * is used for log/metrics/tracing cross-reference only. It is not part of
 * any authentication or authorization decision.
 */
final class RequestIdMiddleware implements MiddlewareContract
{
    /** {@inheritDoc} */
    public function handle(Request $request, Closure $next): Response
    {
        $id = $this->generateRequestId();

        $response = $next($request);

        if (!$response->header('X-Request-Id')) {
            $response = $response->withRequestId($id);
        }

        return $response;
    }

    private function generateRequestId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
