<?php

declare(strict_types=1);

namespace Sahil\Http\Exception;

/**
 * Thrown when a request body uses a content type the application cannot
 * read (415). Rendered as a 415 response: HTML for browsers, JSON for API
 * clients.
 */
final class UnsupportedMediaTypeHttpException extends HttpException
{
    public function __construct(string $message = 'Unsupported media type.', ?\Throwable $previous = null)
    {
        parent::__construct(415, $message, [], $previous);
    }
}
