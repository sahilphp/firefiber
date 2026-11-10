<?php

declare(strict_types=1);

namespace Sahil\Firefiber\Http;

use Sahil\Http\Request;
use Sahil\Http\Response;

/**
 * Fallback for the dedicated action endpoint.
 *
 * {@see FirefiberMiddleware} intercepts every valid targeted Firefiber
 * request before routing reaches this handler. Untargeted requests therefore
 * receive an explicit JSON response instead of being handled as a page.
 */
final class FirefiberActionController
{
    public function handle(Request $request): Response
    {
        return Response::json([
            'status' => 'error',
            'error' => 'Firefiber requests must include the X-Firefiber-Target header.',
        ], 404);
    }

    public function upload(Request $request): Response
    {
        return Response::json([
            'status' => 'error',
            'error' => 'Firefiber upload requests must include the X-Firefiber-Target header.',
        ], 404);
    }
}
