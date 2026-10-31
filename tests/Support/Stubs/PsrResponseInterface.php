<?php

declare(strict_types=1);

namespace Psr\Http\Message;

interface ResponseInterface
{
    public function getStatusCode(): int;

    public function getHeaderLine(string $name): string;

    public function getBody(): StreamInterface;
}
