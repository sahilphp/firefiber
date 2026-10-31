<?php

declare(strict_types=1);

namespace Sahil\Http;

use Closure;
use InvalidArgumentException;

/**
 * Immutable route definition.
 *
 * Handler forms:
 * - Closure: receives (Request, array $sessionParams)
 * - `[class, method]`: class resolvable through the DI container; method
 *   receives (Request, array $requestParams)
 *
 * Path syntax:
 * - Static segments: `/users/list`
 * - Parameter segments: `/users/{id}` — matches one path segment.
 * - Parameter with pattern: `/files/{path:\d+}` — PCRE fragment, must be
 *   balanced/no `/`; validated at registration.
 * - Optional trailing parameter: `/posts/{slug?}`.
 *
 * A trailing `/` on a template is ignored during matching.
 */
final class Route
{
    /** @var list<string> */
    private array $methods;

    /** @var Closure|array{0: class-string, 1: non-empty-string} */
    private readonly Closure|array $handler;

    /** @var list<object|class-string> */
    private array $middleware = [];

    private ?string $routeName = null;

    /**
     * @param list<string> $methods HTTP verbs, upper-case; must be supported
     * @param string $path path template
     * @param Closure|array{0: class-string, 1: non-empty-string} $handler
     */
    public function __construct(
        array $methods,
        private readonly string $path,
        Closure|array $handler,
    ) {
        if ($methods === []) {
            throw new InvalidArgumentException('HTTP: register routes with at least one method.');
        }

        $upper = array_map(static fn (string $m): string => strtoupper(trim($m)), $methods);

        foreach ($upper as $method) {
            if (!in_array($method, Request::SUPPORTED_METHODS, true)) {
                throw new InvalidArgumentException(
                    sprintf('HTTP: method [%s] is not supported by the framework; register valid ones instead.', $method),
                );
            }
        }

        $this->methods = array_values(array_unique($upper));
        $this->handler = self::validateHandler($handler, $path);
        self::validatePath($path);
    }

    /**
     * @param string $path path template
     * @param Closure|array{0: class-string, 1: non-empty-string} $handler
     */
    public static function get(string $path, Closure|array $handler): self
    {
        return (new self(['GET', 'HEAD'], $path, $handler));
    }

    /**
     * @param string $path path template
     * @param Closure|array{0: class-string, 1: non-empty-string} $handler
     */
    public static function post(string $path, Closure|array $handler): self
    {
        return new self(['POST'], $path, $handler);
    }

    /**
     * @param string $path path template
     * @param Closure|array{0: class-string, 1: non-empty-string} $handler
     */
    public static function put(string $path, Closure|array $handler): self
    {
        return new self(['PUT'], $path, $handler);
    }

    /**
     * @param string $path path template
     * @param Closure|array{0: class-string, 1: non-empty-string} $handler
     */
    public static function patch(string $path, Closure|array $handler): self
    {
        return new self(['PATCH'], $path, $handler);
    }

    /**
     * @param string $path path template
     * @param Closure|array{0: class-string, 1: non-empty-string} $handler
     */
    public static function delete(string $path, Closure|array $handler): self
    {
        return new self(['DELETE'], $path, $handler);
    }

    /**
     * @param string $path path template
     * @param Closure|array{0: class-string, 1: non-empty-string} $handler
     */
    public static function options(string $path, Closure|array $handler): self
    {
        return new self(['OPTIONS'], $path, $handler);
    }

    /**
     * Named route (reverse routing via Router::generate()).
     *
     * The route name is a static route `alias` identifier and must be a
     * safe URL-token: letters, digits, dash, dot, underscore.
     */
    public function name(string $routeName): self
    {
        if (preg_match('/^[A-Za-z0-9._-]+$/', $routeName) !== 1) {
            throw new InvalidArgumentException(
                sprintf('HTTP: corridor route name "[%s]" must be a URL-safe token (letters, digits, ._-).', $routeName),
            );
        }

        $clone = clone $this;
        $clone->routeName = $routeName;

        return $clone;
    }

    /**
     * @param object|class-string|list<object|class-string>|null $middleware
     */
    public function middleware(array|object|string|null $middleware): self
    {
        $clone = clone $this;
        $list = $middleware === null ? [] : (is_array($middleware) ? $middleware : [$middleware]);

        $clone->middleware = [...$clone->middleware, ...$list];

        return $clone;
    }

    public function path(): string
    {
        return $this->path;
    }

    /** @return list<string> */
    public function methods(): array
    {
        return $this->methods;
    }

    /** @return Closure|array{0: class-string, 1: non-empty-string} */
    public function handler(): Closure|array
    {
        return $this->handler;
    }

    /** @return list<object|class-string> */
    public function middlewareList(): array
    {
        return $this->middleware;
    }

    public function routeName(): ?string
    {
        return $this->routeName;
    }

    /**
     * Validate that the handler is callable in the framework's conventions
     * before registration (clear, early errors instead of runtime 500s):
     * - Closure, or
     * - `[class-string, method]` where the class exists and exposes the
     *   named method (resolution/invocation still happens through the
     *   container at dispatch time).
     *
     * @param Closure|array{0: class-string, 1: non-empty-string} $handler
     * @return Closure|array{0: class-string, 1: non-empty-string}
     */
    private static function validateHandler(Closure|array $handler, string $path): Closure|array
    {
        if ($handler instanceof Closure) {
            return $handler;
        }

        /** @var mixed $class */
        $class = $handler[0];

        /** @var mixed $method */
        $method = $handler[1];

        if (!is_string($class) || $class === '' || !is_string($method) || $method === '') {
            throw new InvalidArgumentException(
                sprintf('HTTP: route [%s] handler must be a Closure or a [class, method] pair.', $path),
            );
        }

        if (!class_exists($class)) {
            throw new InvalidArgumentException(
                sprintf('HTTP: route [%s] handler class [%s] does not exist (is it autoloaded?).', $path, $class),
            );
        }

        if (!method_exists($class, $method)) {
            throw new InvalidArgumentException(
                sprintf('HTTP: route [%s] handler [%s] does not exist on [%s].', $path, $method, $class),
            );
        }

        return $handler;
    }

    /**
     * Validate that the path template is structured (safe to compile to
     * PCRE) and does not use dangerous constructs.
     */
    private static function validatePath(string $path): void
    {
        if ($path === '' || !str_starts_with($path, '/')) {
            throw new InvalidArgumentException(
                sprintf('HTTP: route path must start with a leading slash, got [%s].', $path),
            );
        }

        $segmentsFound = [];

        foreach (explode('/', trim($path, '/')) as $segment) {
            if ($segment === '') {
                continue;
            }

            if (preg_match('/^\{\w+\??(?::[^{}\/]*)?\}$/', $segment) === 1) {
                $segmentsFound[] = $segment;
                continue;
            }

            if (preg_match('/^[A-Za-z0-9._-]+$/', $segment) !== 1) {
                throw new InvalidArgumentException(
                    sprintf('HTTP: route path segment [%s] contains characters outside the safe set (letters, digits, "._-").', $segment),
                );
            }
        }

        // Segments with `?` must have all prior segments non-optional.
        $optionalSeen = false;

        foreach ($segmentsFound as $segment) {
            $isOptional = str_ends_with($segment, '?}');

            if ($optionalSeen && !$isOptional) {
                throw new InvalidArgumentException(
                    sprintf('HTTP: route path [%s]: required parameters cannot follow optional ones.', $path),
                );
            }

            $optionalSeen = $optionalSeen || $isOptional;
        }
    }

    /**
     * @internal allow cloning with modifications
     */
    public function __clone()
    {
    }
}
