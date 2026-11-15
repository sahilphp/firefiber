<?php

declare(strict_types=1);

namespace Sahil\Http;

use Closure;
use Sahil\Container\Container;
use Sahil\Contracts\Foundation\ApplicationContract;
use Sahil\Frontend\FrontendRenderer;
use Sahil\Frontend\InvalidFrontendConfigurationException;
use Sahil\Http\Exception\HttpException;
use Sahil\Http\Exception\UnsupportedMediaTypeHttpException;
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
 *     keep their status/headers; everything else becomes 500. Browsers get a
 *     designed HTML error page, machine clients keep the JSON contract (see
 *     {@see ErrorRenderer}); messages appear only in debug mode and
 *     installation paths never leak.
 *
 * The kernel stays reentrant: consecutive `handle()` calls in one process
 * (worker simulation) produce fully isolated request lifecycles.
 */
final class Kernel
{
    /** @var list<MiddlewareContract> global (outermost-first) middleware */
    private array $globalMiddleware = [];

    /** @var callable(Throwable, Request): ?Response|null */
    private $exceptionHandler = null;

    private bool $debug = false;

    private ?ErrorRenderer $errorRenderer = null;

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
     * block) and are then handed to this callable with the failing request
     * (a handler that only needs the throwable can ignore it). If it returns
     * a Response, that response is emitted; returning null falls back to the
     * kernel's built-in rendering (see {@see ErrorRenderer}).
     *
     * @param callable(Throwable, Request): ?Response $handler
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
            try {
                // Reject unsupported content types with 415; requests without a
                // content type and JSON/form bodies are allowed. Compare the
                // media type only: real requests carry parameters
                // (`multipart/form-data; boundary=…`, `application/json; charset=utf-8`).
                $contentType = $request->mediaType();
                if ($contentType !== null && !
                    in_array($contentType, ['application/json', 'application/x-www-form-urlencoded', 'multipart/form-data'], true)
                ) {
                    // The long-standing machine-readable 415 payload is kept as
                    // is; a browser gets the HTML page instead.
                    if (ErrorRenderer::expectsJson($request)) {
                        return Response::json(['error' => 'unsupported_media_type'], 415);
                    }

                    throw new UnsupportedMediaTypeHttpException();
                }

                return $this->finalizeFrontend($this->runPipeline($request));
            } catch (HttpException $exception) {
                return $this->handleRendered($this->renderError($exception, $request), $exception, $request);
            } catch (Throwable $throwable) {
                return $this->handleRendered($this->renderError($throwable, $request), $throwable, $request);
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
     *
     * A handler that itself throws — a dead log sink, a broken custom
     * renderer — must not escape the request lifecycle while an error is
     * already being reported, so any failure here degrades to the built-in
     * response instead of becoming a second uncaught exception.
     */
    private function handleRendered(Response $fallback, Throwable $throwable, Request $request): Response
    {
        if ($this->exceptionHandler === null) {
            return $fallback;
        }

        try {
            return ($this->exceptionHandler)($throwable, $request) ?? $fallback;
        } catch (Throwable $handlerFailure) {
            // A handler may deliberately rethrow the original throwable (the
            // test kit's withoutExceptionHandling() relies on that); that is
            // honored. Any *other* failure — a dead log sink, a broken custom
            // renderer — degrades to the built-in response instead of
            // escaping the request lifecycle as a second uncaught exception.
            if ($handlerFailure === $throwable) {
                throw $handlerFailure;
            }

            return $fallback;
        }
    }

    /**
     * Render the throwable, with a last-resort answer when even the error
     * pipeline fails: rendering an error must never raise a second one, so
     * the worst possible outcome is the bare JSON contract instead of an
     * exception escaping {@see handle()}.
     */
    private function renderError(Throwable $throwable, Request $request): Response
    {
        try {
            return $this->errorRenderer()->respond($throwable, $request, $this->debug);
        } catch (Throwable) {
            $status = ExceptionDiagnostics::status($throwable);

            return Response::json(
                $throwable instanceof HttpException
                    ? ['error' => 'http_error', 'status' => $status]
                    : ['error' => 'server_error', 'message' => 'An unexpected server error occurred.'],
                $status,
            );
        }
    }

    /**
     * The renderer is built on first failure only: a booted application that
     * never throws pays no view/cache setup cost.
     */
    private function errorRenderer(): ErrorRenderer
    {
        return $this->errorRenderer ??= ErrorRenderer::fromContainer($this->container);
    }

    /**
     * Global middleware (outermost) → route middleware → handler.
     */
    private function runPipeline(Request $request): Response
    {
        // Global middleware wraps the whole dispatch — including route
        // matching — so request-level concerns (sessions, CSRF, locale
        // selection) apply to error responses for unmatched paths too.
        $dispatch = function () use ($request): Response {
            $match = $this->router->match($request);

            return self::pipelineRunner(
                fn (): Response => $this->dispatchHandler($request, $match),
                $this->routeMiddleware($match),
            )($request);
        };

        return self::pipelineRunner($dispatch, $this->globalMiddleware)($request);
    }

    /**
     * Resolve the matched route's middleware specs into instances.
     *
     * @return list<MiddlewareContract> outermost-first
     */
    private function routeMiddleware(RouteMatch $match): array
    {
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

        return $stack;
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
}
