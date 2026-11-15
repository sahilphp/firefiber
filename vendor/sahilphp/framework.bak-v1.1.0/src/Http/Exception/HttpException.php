<?php

declare(strict_types=1);

namespace Sahil\Http\Exception;

use InvalidArgumentException;
use Sahil\Http\HeaderBag;
use Sahil\Http\Status;
use Throwable;

/**
 * Base class for every HTTP-aware exception: it knows its status and
 * response headers, and can be rendered as a Response by the application
 * error path (which arrives with the HTTP kernel phase).
 */
abstract class HttpException extends \RuntimeException
{
    /** @var array<string, scalar|array<int, scalar>> */
    private array $headers = [];

    /**
     * @param array<string, scalar|array<int, scalar>> $headers response headers
     */
    public function __construct(
        private readonly int $statusCode,
        string $message,
        array $headers = [],
        ?Throwable $previous = null,
    ) {
        if (!Status::isValid($statusCode)) {
            throw new InvalidArgumentException(
                sprintf('HTTP exceptions must carry a valid status code 100-599, got [%d].', $statusCode),
            );
        }

        parent::__construct($message, 0, $previous);

        foreach ($headers as $name => $value) {
            HeaderBag::validateName((string) $name);
        }

        $this->headers = $headers;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Response headers the exception requires on its rendered response.
     *
     * @return array<string, scalar|array<int, scalar>>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }
}
