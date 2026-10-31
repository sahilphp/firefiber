<?php

declare(strict_types=1);

namespace Sahil\Http;

use Closure;
use InvalidArgumentException;
use Sahil\Http\Exception\MethodNotAllowedHttpException;
use Sahil\Http\Exception\NotFoundHttpException;
use Sahil\Http\Middleware\MiddlewareContract;

/**
 * Route table and matching engine.
 *
 * Behavior:
 * - First matching template wins (registration order).
 * - Path pattern (any method) found but verb mismatch →
 *   {@see MethodNotAllowedHttpException} listing allowed verbs.
 * - No path pattern matches → {@see NotFoundHttpException}.
 * - HEAD is handled by GET templates (emitters suppress the body).
 * - Double slashes are not collapsed (distinguishes `/a//b` from `/a/b`).
 */
final class Router
{
    /** @var list<Route> */
    private array $routes = [];

    /** @var array<string, Route> routeName → route */
    private array $named = [];

    /**
     * @param Route ...$routes register one or more routes
     */
    public function register(Route ...$routes): self
    {
        foreach ($routes as $route) {
            if ($route->routeName() !== null) {
                if (array_key_exists($route->routeName(), $this->named)) {
                    throw new InvalidArgumentException(
                        sprintf('Duplication: route name [%s] already registered.', $route->routeName()),
                    );
                }

                $this->named[$route->routeName()] = $route;
            }

            $this->routes[] = $route;
        }

        return $this;
    }

    /**
     * Register prefixed route groups.
     *
     * The definition callback receives a collector router; every route it
     * registers comes back into the main router with:
     *   - `$prefix` prepended to its path, and
     *   - `$middleware` (group level) inherited and prepended in front of
     *     any route-level middleware (outer-inner semantics: group runs
     *     closer to the edge, route middleware closer to the handler).
     *
     * Named routes are detected within the group during registration, so
     * duplicate names across groups fail loudly.
     *
     * @param string $prefix path prefix, e.g. "/admin"; must start with a
     *                       slash; "/admin" + "/dash" = "/admin/dash"
     * @param MiddlewareContract|class-string|list<MiddlewareContract|class-string>|null $middleware
     */
    public function group(string $prefix, Closure $definition, array|MiddlewareContract|string|null $middleware = null): self
    {
        if ($prefix !== '' && (str_starts_with($prefix, '/')) === false) {
            throw new InvalidArgumentException(
                sprintf('HTTP: group prefix must start with a slash, got [%s].', $prefix),
            );
        }

        $collector = new Router();
        $definition($collector);

        $groupMiddleware = $middleware === null ? [] : (is_array($middleware) ? $middleware : [$middleware]);

        if ($prefix === '') {
            $routes = $collector->routes;
        } else {
            $routes = [];
            foreach ($collector->routes as $route) {
                $routes[] = self::rebase($route, $prefix, $groupMiddleware);
            }
        }

        return $this->register(...$routes);
    }

    /**
     * Clone a route with a group prefix and inherited group middleware.
     *
     * @param list<MiddlewareContract|class-string> $groupMiddleware
     */
    private static function rebase(Route $route, string $prefix, array $groupMiddleware): Route
    {
        if ($route->path() === '/') {
            throw new InvalidArgumentException(
                sprintf('HTTP: group [%s] cannot rebase the bare root path; use nested route paths instead.', $prefix),
            );
        }

        $rebased = new $route($route->methods(), $prefix.$route->path(), $route->handler());

        // Preserve non-path metadata (name) — rebased via visibility escape.
        if ($route->routeName() !== null) {
            $rebased = $rebased->name($route->routeName());
        }

        // Inherit group middleware (outermost) then keep route middleware.
        return $rebased->middleware([...$groupMiddleware, ...$route->middlewareList()]);
    }

    /**
     * Match the request against the route table.
     */
    public function match(Request $request): RouteMatch
    {
        $method = $request->method();
        $allowedFor = [];

        foreach ($this->compileMatchable($request->path()) as [$route, $params]) {
            if (!in_array($method, $route->methods(), true)) {
                array_push($allowedFor, ...$route->methods());

                continue;
            }

            return new RouteMatch($route, $params);
        }

        if ($allowedFor !== []) {
            throw new MethodNotAllowedHttpException(
                array_values(array_unique($allowedFor)),
                sprintf('Endpoint [%s] does not accept [%s] requests.', $request->path(), $method),
            );
        }

        throw new NotFoundHttpException();
    }

    /**
     * Has any route been registered?
     */
    public function isEmpty(): bool
    {
        return $this->routes === [];
    }

    public function routeCount(): int
    {
        return count($this->routes);
    }

    public function routeByName(string $name): ?Route
    {
        return $this->named[$name] ?? null;
    }

    /**
     * Reverse-route a named route.
     *
     * @param array<string, scalar> $params segment values
     */
    public function generate(string $name, array $params = []): string
    {
        $route = $this->routeByName($name);

        if ($route === null) {
            throw new InvalidArgumentException(sprintf('Route name [%s] is not registered.', $name));
        }

        $used = [];
        /** @var string|null $generated */
        $generated = preg_replace_callback(
            '/\{(\w+)(?::[^{}\/]*)?(\?)?\}/',
            static function (array $match) use (&$used, $params, $name): string {
                $parameterName = $match[1];
                $optional = isset($match[2]);

                if (!array_key_exists($parameterName, $params)) {
                    if (!$optional) {
                        throw new InvalidArgumentException(
                            sprintf('Reverse routing [%s] misses a value for the required parameter [%s].', $name, $parameterName),
                        );
                    }

                    return '';
                }

                $value = (string) $params[$parameterName];

                if ($value === '' || preg_match('#^[^/?\#]+$#', $value) !== 1) {
                    throw new InvalidArgumentException(
                        sprintf('Reverse routing [%s]: value for [%s] must be a safe path segment.', $name, $parameterName),
                    );
                }

                $used[$parameterName] = $parameterName;

                return $value;
            },
            $route->path(),
        );

        if ($generated === null) {
            throw new InvalidArgumentException(sprintf('Reverse routing [%s] failed to compile its template.', $name));
        }

        $extra = array_diff(array_keys($params), $used);

        if ($extra !== []) {
            throw new InvalidArgumentException(
                sprintf('Reverse routing [%s]: unexpected parameters [%s] (omit them).', $name, implode(', ', $extra)),
            );
        }

        return rtrim($generated, '/') ?: '/';
    }

    /**
     * Compile a concrete request path against every registered template.
     *
     * @return list<array{0: Route, 1: array<string, string>}>
     */
    private function compileMatchable(string $path): array
    {
        $matches = [];

        foreach ($this->routes as $route) {
            $found = self::matchTemplate($route->path(), $path);

            if ($found !== null) {
                $matches[] = [$route, $found];
            }
        }

        return $matches;
    }

    /**
     * Match a single template against the concrete path.
     *
     * @return array<string, string>|null decoded params, or null on mismatch
     */
    private static function matchTemplate(string $template, string $path): ?array
    {
        $template = rtrim($template, '/');
        $path = rtrim($path, '/');

        if ($template === '') {
            // Root template: only the root path matches.
            return $path === '' ? [] : null;
        }

        $patternParts = [];
        $names = [];

        foreach (explode('/', trim($template, '/')) as $segment) {
            if ($segment === '') {
                // Double slashes encode intent; keep the match strict.
                $patternParts[] = '(?:/|)';

                continue;
            }

            $segmentShape = self::parseSegment($segment);

            if ($segmentShape === null) {
                $patternParts[] = '/'.self::staticSeg($segment);

                continue;
            }

            [$name, $pattern, $optional] = $segmentShape;
            $names[$name] = true;

            if ($optional) {
                $patternParts[] = sprintf('(?:/(?P<%s>%s))?', $name, $pattern);

                continue;
            }

            $patternParts[] = sprintf('/(?P<%s>%s)', $name, $pattern);
        }

        // `#` delimiter: the `/` characters of the pattern stay literal.
        $regex = '#^'.implode('', $patternParts).'/?$#';

        if (preg_match($regex, $path, $m) !== 1) {
            return null;
        }

        $params = [];

        foreach (array_keys($names) as $name) {
            $params[$name] = rawurldecode($m[$name] ?? '');
        }

        return $params;
    }

    private static function staticSeg(string $segment): string
    {
        // `#` is the compiled pattern delimiter; also escape `#`.
        return preg_quote($segment, '#');
    }

    /**
     * Parse one template segment into [name, pattern, optional], or null
     * when it is a static segment.
     *
     * @return array{string, string, bool}|null
     */
    private static function parseSegment(string $segment): ?array
    {
        if (!str_starts_with($segment, '{') || !str_ends_with($segment, '}')) {
            return null;
        }

        $body = substr($segment, 1, -1);

        $isOptional = str_ends_with($body, '?');

        if ($isOptional) {
            $body = substr($body, 0, -1);
        }

        if (preg_match('/^\w+(:[^{}\/]*)?$/', $body) !== 1) {
            // Not a well-formed parameter segment → treat as static; the
            // static branch will reject invalid characters anyway.
            return null;
        }

        $named = explode(':', $body, 2);
        $name = $named[0];

        if (preg_match('/^\w+$/', $name) !== 1) {
            return null;
        }

        $pattern = isset($named[1]) && $named[1] !== '' ? $named[1] : '[^/]+';

        return [$name, $pattern, $isOptional];
    }
}
