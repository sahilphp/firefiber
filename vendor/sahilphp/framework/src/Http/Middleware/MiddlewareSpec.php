<?php

declare(strict_types=1);

namespace Sahil\Http\Middleware;

use Closure;
use RuntimeException;
use Sahil\Container\Container;
use Sahil\Http\Request;
use Sahil\Http\Response;

/**
 * A route middleware entry that carries constructor-free parameters.
 *
 * Route middleware entries are either `MiddlewareContract` instances or
 * class-strings, neither of which can express configuration for a middleware
 * that is resolved per request. This value object pairs a class-string with the
 * parameters the middleware should receive:
 *
 *     Route::get('/posts', [PostController::class, 'store'])
 *         ->middleware([new MiddlewareSpec(Authorize::class, ['publish-post'])]);
 *
 * Resolution happens inside the request scope (the kernel resolves route
 * middleware per request), so a spec never captures request state. Parameters
 * are handed to {@see ParameterizedMiddlewareContract::handleWithParameters()};
 * a middleware that does not implement that contract is rejected rather than
 * silently receiving nothing.
 *
 * @see ParameterizedMiddlewareContract
 * @see \Sahil\Http\Kernel::runPipeline()
 */
final class MiddlewareSpec
{
    /** @var class-string */
    private readonly string $class;

    /** @var array<array-key, mixed> */
    private readonly array $parameters;

    /**
     * @param class-string            $class      middleware class resolvable from the container
     * @param array<array-key, mixed> $parameters parameters passed to handleWithParameters()
     */
    public function __construct(string $class, array $parameters = [])
    {
        if (trim($class) === '') {
            throw new RuntimeException('HTTP: a middleware spec requires a non-empty middleware class.');
        }

        $this->class = $class;
        $this->parameters = $parameters;
    }

    /**
     * @param class-string            $class
     * @param array<array-key, mixed> $parameters
     */
    public static function make(string $class, array $parameters = []): self
    {
        return new self($class, $parameters);
    }

    /** @return class-string */
    public function middlewareClass(): string
    {
        return $this->class;
    }

    /** @return array<array-key, mixed> */
    public function parameters(): array
    {
        return $this->parameters;
    }

    /**
     * Resolve this spec into a plain middleware instance the kernel can run.
     *
     * @throws RuntimeException When the class is not middleware, or does not accept parameters
     */
    public function resolve(Container $container): MiddlewareContract
    {
        $middleware = $container->make($this->class);

        if (!$middleware instanceof MiddlewareContract) {
            throw new RuntimeException(sprintf(
                'HTTP: middleware spec class [%s] must implement %s.',
                $this->class,
                MiddlewareContract::class,
            ));
        }

        if ($this->parameters === []) {
            return $middleware;
        }

        if (!$middleware instanceof ParameterizedMiddlewareContract) {
            throw new RuntimeException(sprintf(
                'HTTP: middleware [%s] was given parameters but does not implement %s; '
                .'either drop the parameters or use a middleware that accepts them.',
                $this->class,
                ParameterizedMiddlewareContract::class,
            ));
        }

        return new ParameterizedMiddleware($middleware, $this->parameters);
    }
}
