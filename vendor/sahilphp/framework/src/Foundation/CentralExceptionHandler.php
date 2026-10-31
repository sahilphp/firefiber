<?php

declare(strict_types=1);

namespace Sahil\Foundation;

use Sahil\Http\Exception\HttpException;
use Sahil\Http\Response;
use Sahil\Support\Logging\LoggerContract;
use Throwable;

/**
 * The application's centralized exception handler.
 *
 * One place decides, for every uncaught throwable, two things:
 *  1. {@see report()}: log the event with useful (but non-sensitive)
 *     context — class, message, file/line. HTTP errors (404s, ...) are
 *     `warning`s; genuine faults are `error`s.
 *  2. {@see render()} the HTTP response:
 *     - `HttpException` keeps its status and response headers,
 *     - anything else is a safe `{"error":"server_error"}` 500 — in
 *       debug mode (never default) the message/class are added.
 *     Stack traces and file paths never reach the client in either mode.
 *
 * Configuration errors are distinguishable from HTTP errors: they never
 * render — they are boot failures raised as
 * {@see \Sahil\Config\ConfigurationException} before HTTP dispatch.
 */
final class CentralExceptionHandler
{
    public function __construct(private readonly LoggerContract $logger)
    {
    }

    /**
     * Log the throwable (never arguments or stack frames) for triage.
     */
    public function report(Throwable $throwable): void
    {
        $isHttp = $throwable instanceof HttpException;

        $this->logger->log($isHttp ? 'warning' : 'error', 'Uncaught '.$throwable::class, [
            'exception_class' => $throwable::class,
            'message' => $throwable->getMessage(),
            'file' => $throwable->getFile(),
            'line' => $throwable->getLine(),
            'status' => $throwable instanceof HttpException ? $throwable->statusCode() : null,
        ]);
    }

    /**
     * Rendering contract for the HTTP kernel.
     */
    public function render(Throwable $throwable, bool $debug): Response
    {
        if ($throwable instanceof HttpException) {
            $debugMessage = $debug ? $throwable->getMessage() : '';

            $payload = $debugMessage === ''
                ? ['error' => 'http_error', 'status' => $throwable->statusCode()]
                : ['error' => 'http_error', 'status' => $throwable->statusCode(), 'message' => $debugMessage];

            return Response::json($payload, $throwable->statusCode())
                ->withHeaders($throwable->getHeaders());
        }

        return Response::json($debug
            ? [
                'error' => 'server_error',
                'message' => $throwable->getMessage(),
                'exception' => $throwable::class,
            ]
            : ['error' => 'server_error'], 500);
    }
}
