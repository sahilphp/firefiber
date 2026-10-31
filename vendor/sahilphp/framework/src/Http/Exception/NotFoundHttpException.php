<?php

declare(strict_types=1);

namespace Sahil\Http\Exception;

/**
 * Thrown when the request path matches no route (the router will emit
 * these from HTTP 3.2 onward). Rendered as a 404 response with no detail
 * leaks.
 */
final class NotFoundHttpException extends HttpException
{
    public function __construct(string $message = 'Not found.', ?\Throwable $previous = null)
    {
        parent::__construct(404, $message, [], $previous);
    }
}
