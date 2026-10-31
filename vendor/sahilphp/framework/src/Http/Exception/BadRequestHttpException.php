<?php

declare(strict_types=1);

namespace Sahil\Http\Exception;

/**
 * The request cannot be processed as it stands (malformed syntax, bad
 * JSON body, unsupported method...). Emitted as HTTP 400 responses.
 */
final class BadRequestHttpException extends HttpException
{
    public function __construct(string $message = 'Bad request.', ?\Throwable $previous = null)
    {
        parent::__construct(400, $message, [], $previous);
    }

    /**
     * 400 for an unparsable JSON request body. The detail from the parse
     * exception is contained: it never echoes the raw body back.
     */
    public static function malformedJson(\JsonException $previous): self
    {
        return new self(
            sprintf('Malformed JSON request body: %s.', $previous->getMessage()),
            $previous,
        );
    }
}
