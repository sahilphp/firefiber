<?php

declare(strict_types=1);

namespace Slim\Psr7;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

final class HttpCodes
{
    public const int BAD_REQUEST = 400;
    public const int UNPROCESSABLE_ENTITY = 422;
    public const int CONFLICT = 409;
    public const int SERVICE_UNAVAILABLE = 503;
}

final class Stream implements StreamInterface
{
    public function __construct(private string $contents = '')
    {
    }

    public function __toString(): string
    {
        return $this->contents;
    }
}

final class Response implements ResponseInterface
{
    /** @param array<string, string> $headers */
    public function __construct(
        private int $status = 200,
        private array $headers = [],
        private string $body = '',
    ) {
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }

    public function getHeaderLine(string $name): string
    {
        return $this->headers[$name] ?? '';
    }

    public function getBody(): StreamInterface
    {
        return new Stream($this->body);
    }

    public function body(): string
    {
        return $this->body;
    }
}
