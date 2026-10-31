<?php

declare(strict_types=1);

namespace Sahil\Http\Middleware;

use Closure;
use Sahil\Http\Cors\Config;
use Sahil\Http\Request;
use Sahil\Http\Response;

/**
 * Apply safe CORS headers to responses.
 *
 * CORS is an HTTP header negotiation, not an authentication mechanism:
 * it only tells browsers which origins may read responses. It is always
 * combined with, never a replacement for, session/token
 * authentication and authorization.
 *
 * The middleware answers `OPTIONS` preflights with `204 No Content` and
 * adds the negotiation headers to subsequent requests. `Access-Control-*
 * }` headers are only present when the configured origin matches.
 */
final class Cors implements MiddlewareContract
{
    public function __construct(private readonly Config $config)
    {
    }

    /** {@inheritDoc} */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->method() === 'OPTIONS') {
            return $this->handlePreflight($request, $response);
        }

        if (!$this->config->shouldApply($request)) {
            return $response;
        }

        return $this->applyHeaders($request, $response);
    }

    private function handlePreflight(Request $request, Response $response): Response
    {
        if (!$this->config->shouldApply($request)) {
            return $response->withStatus(204);
        }

        return $this
            ->applyHeaders($request, $response)
            ->withStatus(204);
    }

    private function applyHeaders(Request $request, Response $response): Response
    {
        $response = $response
            ->withHeader('Access-Control-Allow-Origin', $this->matchedOrigin($request))
            ->withHeader('Access-Control-Allow-Methods', $this->config->allowedMethods())
            ->withHeader('Access-Control-Allow-Headers', $this->config->allowedHeaders())
            ->withHeader('Access-Control-Max-Age', (string) $this->config->maxAge());

        if ($this->config->allowCredentials()) {
            $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
        }

        if ($this->config->exposedHeaders()) {
            $response = $response->withHeader('Access-Control-Expose-Headers', $this->config->exposedHeaders());
        }

        return $response;
    }

    private function matchedOrigin(Request $request): string
    {
        $origin = $request->header('Origin');

        if ($origin === null) {
            return '*';
        }

        $allowed = $this->config->allowedOrigins();

        if ($this->config->allowedOrigins() === ['*']) {
            return $origin;
        }

        foreach ($allowed as $allowedOrigin) {
            if ($origin === $allowedOrigin) {
                return $origin;
            }
        }

        return '*';
    }
}
