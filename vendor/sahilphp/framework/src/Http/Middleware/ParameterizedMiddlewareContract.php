<?php

declare(strict_types=1);

namespace Sahil\Http\Middleware;

use Closure;
use Sahil\Http\Request;
use Sahil\Http\Response;

/**
 * Middleware that accepts configuration parameters at resolution time.
 *
 * The kernel calls `MiddlewareContract::handle($request, $next)` with exactly
 * two arguments, so middleware that needs per-route configuration (abilities,
 * limits, resource names) cannot receive it through `handle()`. Such middleware
 * implements this contract and is registered through a
 * {@see MiddlewareSpec}; the kernel then resolves it, passes the parameters here
 * once, and wraps the result in a plain `MiddlewareContract`.
 *
 * @see MiddlewareSpec
 * @see \Sahil\Http\Kernel::runPipeline()
 */
interface ParameterizedMiddlewareContract
{
    /**
     * Handle the request with the parameters declared on the route.
     *
     * @param array<array-key, mixed> $parameters parameters from the route's MiddlewareSpec
     */
    public function handleWithParameters(Request $request, Closure $next, array $parameters): Response;
}
