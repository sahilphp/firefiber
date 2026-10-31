<?php

declare(strict_types=1);

namespace Psr\Http\Message;

interface ServerRequestInterface
{
    public function getHeaderLine(string $name): string;

    public function getCookieParam(string $name, mixed $default = null): mixed;

    public function getBody(): StreamInterface;
}
