<?php

declare(strict_types=1);

namespace Sahil\Http;

use InvalidArgumentException;

/**
 * HTTP status codes and validation for Response construction.
 */
final class Status
{
    /** @var array<int, string> */
    private const REASONS = [
        100 => 'Continue',
        101 => 'Switching Protocols',
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content',
        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        307 => 'Temporary Redirect',
        308 => 'Permanent Redirect',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed',
        413 => 'Content Too Large',
        414 => 'URI Too Long',
        415 => 'Unsupported Media Type',
        416 => 'Range Not Satisfiable',
        422 => 'Unprocessable Content',
        429 => 'Too Many Requests',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
    ];

    /**
     * Whether `$code` is a valid HTTP status (100–599).
     */
    public static function isValid(int $code): bool
    {
        return $code >= 100 && $code <= 599;
    }

    /**
     * Standard reason phrase, or `Unknown` for exotic codes.
     */
    public static function reason(int $code): string
    {
        if (!self::isValid($code)) {
            throw new InvalidArgumentException(sprintf('Status code [%d] is outside the valid HTTP range 100-599.', $code));
        }

        return self::REASONS[$code] ?? 'Unknown';
    }

    /**
     * Whether a status may carry a body (1xx, 204 and 304 must not).
     */
    public static function allowsBody(int $code): bool
    {
        return self::isValid($code)
            && $code >= 200
            && $code !== 204
            && $code !== 304;
    }
}
