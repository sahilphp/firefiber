<?php

declare(strict_types=1);

namespace Psr\Http\Message;

interface StreamInterface
{
    public function __toString(): string;
}
