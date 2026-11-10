<?php

declare(strict_types=1);

namespace Sahil\Http\Middleware;

use Closure;
use Sahil\Http\Request;
use Sahil\Http\Response;

/**
 * Adapter that supplies parameters to a {@see ParameterizedMiddlewareContract}.
 *
 * @internal created only by {@see MiddlewareSpec::resolve()}
 */
final class ParameterizedMiddleware implements MiddlewareContract
{
    /**
     * @param ParameterizedMiddlewareContract $middleware the configured middleware
     * @param array<array-key, mixed>         $parameters route parameters
     */
    public function __construct(
        private readonly ParameterizedMiddlewareContract $middleware,
        private readonly array $parameters,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        return $this->middleware->handleWithParameters($request, $next, $this->parameters);
    }
}
