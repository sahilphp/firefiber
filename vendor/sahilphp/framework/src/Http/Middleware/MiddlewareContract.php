<?php

declare(strict_types=1);

namespace Sahil\Http\Middleware;

use Closure;
use Sahil\Http\Request;
use Sahil\Http\Response;

/**
 * HTTP middleware contract.
 *
 * Middleware wrap the inner pipeline: always call `$next($request)` once
 * (before or after doing your own work) — the framework depends on that
 * for a single emitted response.
 */
interface MiddlewareContract
{
    public function handle(Request $request, Closure $next): Response;
}
