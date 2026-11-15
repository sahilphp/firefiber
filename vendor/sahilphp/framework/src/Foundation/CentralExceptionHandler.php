<?php

declare(strict_types=1);

namespace Sahil\Foundation;

use Sahil\Http\ErrorRenderer;
use Sahil\Http\Exception\HttpException;
use Sahil\Http\ExceptionDiagnostics;
use Sahil\Http\Request;
use Sahil\Http\Response;
use Sahil\Support\Logging\LoggerContract;
use Throwable;

/**
 * The application's centralized exception handler.
 *
 * One place decides, for every uncaught throwable, two things:
 *  1. {@see report()}: log the full diagnostic picture — class, message,
 *     file/line, HTTP status, the previous-cause chain, the stack trace and
 *     the failing request's method/URI/correlation id. HTTP errors (404s,
 *     ...) are `warning`s; genuine faults are `error`s. Trace arguments are
 *     never logged (they can carry secrets) and a broken log sink can never
 *     escalate: the reporting failure is swallowed so the original error is
 *     still rendered.
 *  2. {@see render()} the HTTP response through {@see ErrorRenderer}: browsers
 *     get a designed HTML error page, machine clients keep the JSON contract,
 *     `HttpException`s keep their status and headers, and anything else is a
 *     500. In debug mode (never default) the response carries the structured
 *     diagnostic payload — type, message, file, line, previous cause and
 *     stack trace; in production the client only sees the generic contract.
 *
 * Configuration errors are distinguishable from HTTP errors: they never
 * render — they are boot failures raised as
 * {@see \Sahil\Config\ConfigurationException} before HTTP dispatch.
 */
final class CentralExceptionHandler
{
    /**
     * @param ErrorRenderer|null $errors renderer wired to the application's
     *        views; without one, responses stay JSON (and built-in HTML when a
     *        request is passed and the pages cannot be rendered)
     */
    public function __construct(
        private readonly LoggerContract $logger,
        private readonly ?ErrorRenderer $errors = null,
    ) {
    }

    /**
     * Log the throwable with complete diagnostics: class, message, file/line,
     * HTTP status, the previous-cause chain, the argument-free stack trace
     * and — when a request is supplied — its method, URI and `X-Request-Id`
     * correlation header. No request body, header map or trace argument is
     * ever recorded.
     *
     * A failing log sink must not produce a second failure while the first is
     * still being handled, so a logging error is swallowed here; the original
     * throwable continues to {@see render()}.
     */
    public function report(Throwable $throwable, ?Request $request = null): void
    {
        $isHttp = $throwable instanceof HttpException;

        $context = [
            'exception_class' => $throwable::class,
            'message' => $throwable->getMessage(),
            'file' => $throwable->getFile(),
            'line' => $throwable->getLine(),
            'status' => $throwable instanceof HttpException ? $throwable->statusCode() : 500,
            'previous' => ExceptionDiagnostics::previous($throwable->getPrevious()),
            'trace' => ExceptionDiagnostics::trace($throwable),
        ];

        if ($request !== null) {
            $context['request'] = [
                'method' => $request->method(),
                'uri' => $request->uri(),
            ];

            $requestId = $request->header('X-Request-Id');

            if (is_string($requestId) && $requestId !== '') {
                $context['request_id'] = $requestId;
            }
        }

        try {
            $this->logger->log(
                $isHttp ? 'warning' : 'error',
                'Uncaught '.$throwable::class,
                $context,
            );
        } catch (Throwable) {
            // The log sink is broken; the original throwable still renders.
        }
    }

    /**
     * Rendering contract for the HTTP kernel.
     *
     * With a request, the answer matches what the client expects (HTML page or
     * JSON); without one, the JSON contract is kept for standalone callers.
     */
    public function render(Throwable $throwable, bool $debug = false, ?Request $request = null): Response
    {
        return ($this->errors ?? new ErrorRenderer())->respond($throwable, $request, $debug);
    }
}
