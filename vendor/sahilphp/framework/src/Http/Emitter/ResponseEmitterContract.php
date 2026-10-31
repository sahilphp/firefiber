<?php

declare(strict_types=1);

namespace Sahil\Http\Emitter;

use RuntimeException;
use Sahil\Http\Request;
use Sahil\Http\Response;

/**
 * Contract that writes a Response to the output channel.
 */
interface ResponseEmitterContract
{
    /**
     * @throws RuntimeException when output already started or headers
     *                          cannot be delivered
     */
    public function emit(Response $response, ?Request $request = null): void;
}
