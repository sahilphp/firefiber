<?php

declare(strict_types=1);

namespace Sahil\Http\Emitter;

use Sahil\Http\Request;
use Sahil\Http\Response;
use Sahil\Http\Status;

/**
 * Emits a Response using PHP's native header()/echo.
 *
 * Rules:
 * - Refuses to run after output has already reached the client; header
 *   emission then fails loudly with {@see HeadersAlreadySentException}
 *   instead of silently producing a corrupted response.
 * - HEAD requests (and 304/1xx responses) get the exact headers (incl.
 *   Content-Length of the would-be body) with no body bytes.
 */
final class DefaultResponseEmitter implements ResponseEmitterContract
{
    public function emit(Response $response, ?Request $request = null): void
    {
        if (headers_sent($file, $line)) {
            throw new HeadersAlreadySentException(
                sprintf('Output already started at %s:%d; the response could not be delivered.', $file, $line),
            );
        }

        $protocol = $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1';

        http_response_code($response->status());

        // Status line driven directly so SAPI quirks do not rewrite codes.
        header_remove();
        header(sprintf('%s %d %s', $protocol, $response->status(), Status::reason($response->status())), true, $response->status());

        foreach ($response->headers() as $name => $values) {
            foreach ($values as $value) {
                header($name.': '.$value, false);
            }
        }

        if ($request !== null && $response->mustOmitBodyFor($request)) {
            return;
        }

        echo $response->body();
    }
}
