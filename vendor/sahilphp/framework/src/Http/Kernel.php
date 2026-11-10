<?php

declare(strict_types=1);

namespace Sahil\Http;

use Closure;
use Sahil\Container\Container;
use Sahil\Contracts\Foundation\ApplicationContract;
use Sahil\Frontend\FrontendRenderer;
use Sahil\Frontend\InvalidFrontendConfigurationException;
use Sahil\Http\Exception\HttpException;
use Sahil\Http\Middleware\MiddlewareContract;
use Sahil\Http\Middleware\MiddlewareSpec;
use Throwable;

/**
 * HTTP kernel: middleware pipeline, controller resolution, request scope
 * lifecycle, and exception→Response rendering.
 *
 * Composition:
 *   - the container resolves every middleware and controller handler,
 *   - each {@see handle()} runs inside one request scope (so `scoped()`
 *     bindings resolve; they are dropped when the scope ends, even on
 *     error paths), and
 *   - every exception is converted to a safe response: `HttpException`s
 *     keep their status/headers; everything else becomes 500 (message
 *     only in debug mode, installation paths never leak).
 *
 * The kernel stays reentrant: consecutive `handle()` calls in one process
 * (worker simulation) produce fully isolated request lifecycles.
 */
final class Kernel
{
    /** @var list<MiddlewareContract> global (outermost-first) middleware */
    private array $globalMiddleware = [];

    /** @var callable(Throwable): ?Response|null */
    private $exceptionHandler = null;

    private bool $debug = false;

    public function __construct(
        private readonly Router $router,
        private readonly Container $container,
        private readonly ApplicationContract $application,
        private readonly ?FrontendRenderer $frontend = null,
    ) {
    }

    public function useDebugMode(bool $debug): self
    {
        $clone = clone $this;
        $clone->debug = $debug;

        return $clone;
    }

    public function using(MiddlewareContract ...$middleware): self
    {
        $clone = clone $this;
        $clone->globalMiddleware = [...$clone->globalMiddleware, ...array_values($middleware)];

        return $clone;
    }

    /**
     * Install an application-level exception handler.
     *
     * Middleware and handler exceptions unwind through the pipeline (in
     * reverse registration order, running every `finally` and finalize
     * block) and are then handed to this callable. If it returns a
     * Response, that response is emitted; returning null falls back to the
     * kernel's built-in safe rendering (HttpException status, otherwise a
     * debug-aware 500 without leaking internals).
     *
     * @param callable(Throwable): ?Response $handler
     */
    public function usingExceptionHandler(callable $handler): self
    {
        $clone = clone $this;
        $clone->exceptionHandler = $handler;

        return $clone;
    }

    /**
     * Run one request through the router, middleware and scope lifecycle.
     */
    public function handle(Request $request): Response
    {
        return $this->application->isolatedRequest(function () use ($request): Response {
            // Validate content type: reject unsupported types with 415,
            // allow requests without a content type, and allow JSON/form data.
            $contentType = $request->contentType();
            if ($contentType !== null && !
                in_array($contentType, ['application/json', 'application/x-www-form-urlencoded', 'multipart/form-data'], true)
            ) {
                return Response::json(['error' => 'unsupported_media_type'], 415)
                    ->withHeader('Content-Type', 'application/json');
            }

            try {
                return $this->finalizeFrontend($this->runPipeline($request));
            } catch (HttpException $exception) {
                return $this->handleRendered($this->renderHttpException($exception), $exception);
            } catch (Throwable $throwable) {
                return $this->handleRendered($this->renderThrowable($throwable), $throwable);
            }
        });
    }

    /**
     * Turn a frontend-tagged response into a complete document.
     *
     * Native responses pass through untouched. A frontend response without
     * a configured renderer is a configuration error, not a silently broken
     * page, so it fails with an actionable message.
     */
    private function finalizeFrontend(Response $response): Response
    {
        $target = $response->frontend();

        if ($target === null) {
            return $response;
        }

        if ($this->frontend === null) {
            throw new InvalidFrontendConfigurationException(sprintf(
                'The response targets the [%s] frontend, but the HTTP kernel has no %s. '
                .'Register %s and pass it to the Kernel constructor (the JavaScript starter-kits do this in their provider).',
                $target,
                FrontendRenderer::class,
                FrontendRenderer::class,
            ));
        }

        return $this->frontend->render($response);
    }

    /**
     * Give the application exception handler (when installed) the chance
     * to replace the built-in rendering; a null return keeps the fallback.
     */
    private function handleRendered(Response $fallback, Throwable $throwable): Response
    {
        if ($this->exceptionHandler === null) {
            return $fallback;
        }

        return ($this->exceptionHandler)($throwable) ?? $fallback;
    }

    /**
     * Global middleware (outermost) → route middleware → handler.
     */
    private function runPipeline(Request $request): Response
    {
        $match = $this->router->match($request);

        $pipeline = fn (): Response => $this->dispatchHandler($request, $match);

        /** @var list<MiddlewareContract> $stack outermost-first */
        $stack = [];

        foreach ($match->route()->middlewareList() as $spec) {
            if ($spec instanceof MiddlewareContract) {
                $stack[] = $spec;

                continue;
            }

            // A spec pairs a middleware class with per-route parameters (the
            // kernel passes none to handle()); it resolves inside this
            // request scope, so nothing request-specific is captured earlier.
            if ($spec instanceof MiddlewareSpec) {
                $stack[] = $spec->resolve($this->container);

                continue;
            }

            $resolved = $this->container->make((string) $spec);

            if (!$resolved instanceof MiddlewareContract) {
                throw new \RuntimeException(
                    sprintf('Configured route middleware [%s] must expose MiddlewareContract::handle(...).', is_object($spec) ? get_class($spec) : (string) $spec),
                );
            }

            $stack[] = $resolved;
        }

        $inner = self::pipelineRunner($pipeline, [...$this->globalMiddleware, ...$stack]);

        return $inner($request);
    }

    /**
     * @param callable(Request): Response $core
     * @param list<MiddlewareContract> $stack outermost-first
     */
    private static function pipelineRunner(callable $core, array $stack): Closure
    {
        return array_reduce(
            array_reverse($stack),
            static fn (Closure $next, MiddlewareContract $middleware): Closure
                => static fn (Request $request): Response => $middleware->handle($request, $next),
            /** @var callable(Request): Response $core */
            static fn (Request $request): Response => $core($request),
        );
    }

    /**
     * Resolve the handler from the container and call it.
     */
    private function dispatchHandler(Request $request, RouteMatch $match): Response
    {
        $handler = $match->route()->handler();

        if ($handler instanceof Closure) {
            return ($handler)($request, $match->params());
        }

        [$class, $method] = $handler;

        $controller = $this->container->make($class);

        $response = $controller->{$method}($request, $match->params());

        if (!$response instanceof Response) {
            throw new \RuntimeException(
                sprintf('Handler must return %s; got %s.', Response::class, get_debug_type($response)),
            );
        }

        return $response;
    }

    private function renderHttpException(HttpException $exception): Response
    {
        $payload = [
            'error' => 'http_error',
            'status' => $exception->statusCode(),
            'message' => $this->debug ? $exception->getMessage() : null,
        ];

        return Response::json(array_filter($payload, static fn (mixed $v): bool => $v !== null), $exception->statusCode())
            ->withHeaders($exception->getHeaders());
    }

    private function renderThrowable(Throwable $throwable): Response
    {
        return Response::json($this->debug
            ? [
                'error' => 'server_error',
                'message' => $throwable->getMessage(),
            ]
            : ['error' => 'server_error'], 500);
    }
}
