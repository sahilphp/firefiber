<?php

declare(strict_types=1);

namespace Sahil\Http;

use Sahil\Http\Exception\HttpException;
use Throwable;

/**
 * Extracts the full diagnostic picture of a throwable for error pages, JSON
 * diagnostics and log records: its type, message, source location, previous
 * cause chain and stack trace.
 *
 * One extractor serves all three sinks so the information presented in debug
 * mode is exactly the information written to the application log — a
 * diagnostic that reaches a developer page also reaches the log.
 *
 * Stack frames are rendered without argument values: arguments can carry
 * secrets (passwords, tokens, request payloads) and no error surface should
 * ever materialize them.
 */
final class ExceptionDiagnostics
{
    private function __construct()
    {
    }

    /**
     * The HTTP status a throwable maps to: `HttpException`s keep their own
     * status; everything else is a 500.
     */
    public static function status(Throwable $throwable): int
    {
        return $throwable instanceof HttpException ? $throwable->statusCode() : 500;
    }

    /**
     * The complete structured payload: type, message, location, status,
     * previous cause chain and stack trace.
     *
     * @return array{
     *     type: string,
     *     message: string,
     *     file: string,
     *     line: int,
     *     status: int,
     *     previous: array<string, mixed>|null,
     *     trace: list<string>
     * }
     */
    public static function payload(Throwable $throwable): array
    {
        return [
            'type' => $throwable::class,
            'message' => $throwable->getMessage(),
            'file' => $throwable->getFile(),
            'line' => $throwable->getLine(),
            'status' => self::status($throwable),
            'previous' => self::previous($throwable->getPrevious()),
            'trace' => self::trace($throwable),
        ];
    }

    /**
     * The previous-exception chain as a nested structure, or null. Cause
     * information is never dropped when exceptions are wrapped or rethrown.
     *
     * @return array{type: string, message: string, file: string, line: int, previous: array<string, mixed>|null}|null
     */
    public static function previous(?Throwable $throwable): ?array
    {
        if ($throwable === null) {
            return null;
        }

        return [
            'type' => $throwable::class,
            'message' => $throwable->getMessage(),
            'file' => $throwable->getFile(),
            'line' => $throwable->getLine(),
            'previous' => self::previous($throwable->getPrevious()),
        ];
    }

    /**
     * Stack frames rendered like PHP's own trace format but without argument
     * values: `#0 file(line): Class::method()` /
     * `#0 file(line): Class->method()`.
     *
     * @return list<string>
     */
    public static function trace(Throwable $throwable): array
    {
        $frames = [];

        foreach ($throwable->getTrace() as $index => $frame) {
            $call = '';

            if (isset($frame['class'])) {
                $call .= $frame['class'].($frame['type'] ?? '->');
            }

            $call .= ($frame['function'] ?? '{main}').'()';

            $frames[] = isset($frame['file'])
                ? sprintf('#%d %s(%s): %s', $index, $frame['file'], (string) ($frame['line'] ?? ''), $call)
                : sprintf('#%d [internal function]: %s', $index, $call);
        }

        return $frames;
    }

    /**
     * One-line summary of the previous cause for display:
     * `PDOException: Connection refused (file.php:12)` — or null.
     */
    public static function previousSummary(Throwable $throwable): ?string
    {
        $previous = $throwable->getPrevious();

        if ($previous === null) {
            return null;
        }

        return sprintf(
            '%s: %s (%s:%d)',
            $previous::class,
            $previous->getMessage(),
            $previous->getFile(),
            $previous->getLine(),
        );
    }
}
