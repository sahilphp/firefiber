<?php

declare(strict_types=1);

namespace Sahil\Http\Middleware;

use Closure;
use Sahil\Http\RateLimit\StoreInterface;
use Sahil\Http\Request;
use Sahil\Http\Response;

/**
 * Edge-level route middleware that enforces a configured per-key rate
 * limit and returns a structured `429 Too Many Requests` response when the
 * limit is exceeded.
 *
 * The middleware is purely defensive: it guards a route (or a group of
 * routes sharing one budget) and never makes authentication or
 * authorization decisions. It only inspects the configured key and the
 * storage layer.
 */
final class Throttle implements MiddlewareContract
{
    /**
     * @param string         $key   bucket name passed to the store
     * @param StoreInterface $store store that holds the counters
     */
    public function __construct(
        private readonly string $key,
        private readonly StoreInterface $store,
    ) {
    }

    /** {@inheritDoc} */
    public function handle(Request $request, Closure $next): Response
    {
        $result = $this->store->increment($this->key);

        if (!$result->allowed) {
            return Response::json([
                'error' => 'rate_limit',
                'message' => 'Too many requests. Try again later.',
                'remaining' => $result->remaining,
            ], 429)
                ->withHeader('Retry-After', (string) $result->retryAfter);
        }

        return $next($request);
    }

}
