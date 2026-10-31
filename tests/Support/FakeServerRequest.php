<?php

declare(strict_types=1);

namespace Sahil\Firefiber\Tests\Support;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Slim\Psr7\Stream;

final class FakeServerRequest implements ServerRequestInterface
{
    /**
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     */
    public function __construct(
        private string $body = '',
        private array $headers = [],
        private array $cookies = [],
    ) {
    }

    public function getHeaderLine(string $name): string
    {
        return $this->headers[$name] ?? '';
    }

    public function getCookieParam(string $name, mixed $default = null): mixed
    {
        return $this->cookies[$name] ?? $default;
    }

    public function getBody(): StreamInterface
    {
        return new Stream($this->body);
    }
}
