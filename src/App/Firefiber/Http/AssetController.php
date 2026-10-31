<?php

declare(strict_types=1);

namespace Sahil\Firefiber\Http;

use Sahil\Firefiber\Assets\AssetRepository;
use Sahil\Firefiber\Assets\AssetUnavailableException;
use Sahil\Http\Request;
use Sahil\Http\Response;

/**
 * Serves an allow-listed Firefiber asset directly from the installed
 * Composer package.
 *
 * The controller never receives a path: the route parameter is a public
 * catalog key. Unknown keys and lifecycle failures return generic responses
 * with no filesystem detail.
 *
 * Cache policy:
 * - a URL carrying the current content hash is immutable for a year;
 * - other URLs are briefly cacheable and revalidate through ETag/Last-Modified;
 * - error responses are never cached.
 */
final class AssetController
{
    private const IMMUTABLE_CACHE = 'public, max-age=31536000, immutable';

    private const REVALIDATING_CACHE = 'public, max-age=3600, must-revalidate';

    public function __construct(
        private readonly ?AssetRepository $assets = null,
    ) {
    }

    /**
     * Route handler used by the framework's HTTP kernel.
     *
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params = []): Response
    {
        if (!$request->methodIs('GET') && !$request->methodIs('HEAD')) {
            return Response::json(['error' => 'method_not_allowed'], 405)
                ->withHeaders([
                    'Allow' => 'GET, HEAD',
                    'Cache-Control' => 'no-store',
                    'X-Content-Type-Options' => 'nosniff',
                ]);
        }

        $assets = $this->assets ?? new AssetRepository();
        $name = isset($params['asset']) ? (string) $params['asset'] : '';
        $asset = $assets->find($name);

        if ($asset === null) {
            return self::notFound();
        }

        try {
            $contents = $asset->contents();
            $lastModified = $asset->lastModified();
        } catch (AssetUnavailableException) {
            return Response::json(['error' => 'asset_unavailable'], 503)
                ->withHeaders([
                    'Cache-Control' => 'no-store',
                    'X-Content-Type-Options' => 'nosniff',
                ]);
        }

        $version = (string) ($request->queryParameter('v') ?? '');
        $headers = [
            'Content-Type' => $asset->mimeType(),
            'Cache-Control' => $assets->versionMatches($name, $version)
                ? self::IMMUTABLE_CACHE
                : self::REVALIDATING_CACHE,
            'ETag' => $asset->etag(),
            'Last-Modified' => gmdate('D, d M Y H:i:s', $lastModified).' GMT',
            'X-Content-Type-Options' => 'nosniff',
        ];

        if (self::matchesEtag($request->header('If-None-Match'), $asset->etag())) {
            return new Response(304, $headers, '');
        }

        $ifModifiedSince = $request->header('If-Modified-Since');

        if ($ifModifiedSince !== null) {
            $since = strtotime($ifModifiedSince);

            if ($since !== false && $since >= $lastModified) {
                return new Response(304, $headers, '');
            }
        }

        return new Response(200, $headers, $contents);
    }

    private static function notFound(): Response
    {
        return Response::json(['error' => 'not_found'], 404)
            ->withHeaders([
                'Cache-Control' => 'no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
    }

    private static function matchesEtag(?string $header, string $etag): bool
    {
        if ($header === null || trim($header) === '') {
            return false;
        }

        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);

            if ($candidate === '*') {
                return true;
            }

            if (str_starts_with($candidate, 'W/')) {
                $candidate = substr($candidate, 2);
            }

            if (hash_equals($etag, $candidate)) {
                return true;
            }
        }

        return false;
    }
}
