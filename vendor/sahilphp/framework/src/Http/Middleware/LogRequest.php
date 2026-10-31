<?php

declare(strict_types=1);

namespace Sahil\Http\Middleware;

use Closure;
use Sahil\Http\Exception\HttpException;
use Sahil\Http\Request;
use Sahil\Http\Response;
use Sahil\Support\Logging\RequestLogger;

/**
 * Structured request logging middleware: exactly one JSON event per request.
 *
 * The envelope is fixed (method, path, status, content type, client IP,
 * user agent, request id) so query and response log streams can be joined
 * on `request_id`. Sensitive HTTP headers and request-body fields are
 * redacted by {@see Redactor} before the event is written.
 *
 * - Success path: logs once, after the response is built, at INFO.
 * - Error path: logs once, then re-throws so the application exception
 *   handler (and the kernel's 500 rendering) still runs.
 *
 * The logger is only enabled when a `RequestLogger` service is bound in
 * the container (observability provider). Without one the middleware is
 * a no-op and adds no overhead.
 */
final class LogRequest implements MiddlewareContract
{
    public function __construct(private readonly ?RequestLogger $logger = null)
    {
    }

    /** {@inheritDoc} */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->logger === null) {
            return $next($request);
        }

        try {
            $response = $next($request);

            $this->logger->record(
                'request handled',
                $response->status(),
                $this->context($request),
            );

            return $response;
        } catch (\Throwable $throwable) {
            $this->logger->record(
                'request errored',
                $throwable instanceof HttpException
                    ? $throwable->statusCode()
                    : 500,
                $this->context($request),
            );

            throw $throwable;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function context(Request $request): array
    {
        $payload = [];

        if ($request->hasJsonBody()) {
            $payload['body'] = $request->json();
        } elseif ($request->hasFormBody()) {
            $payload['body'] = $request->form();
        }

        return $payload;
    }
}
