<?php

declare(strict_types=1);

namespace Sahil\Http\Middleware;

use Closure;
use Sahil\Http\Request;
use Sahil\Http\Response;

/**
 * Adds conservative security headers to every response.
 *
 * Defaults (only set when the response does not already define them):
 * - `X-Content-Type-Options: nosniff` — no MIME sniffing;
 * - `X-Frame-Options: DENY` — clickjacking protection (use SAMEORIGIN by
 *   passing an override if the app must be framed by itself);
 * - `Referrer-Policy: strict-origin-when-cross-origin`;
 * - `Permissions-Policy` — camera, microphone and geolocation disabled;
 * - `Cross-Origin-Opener-Policy: same-origin`.
 *
 * A Content-Security-Policy is deliberately not set by default: a wrong policy
 * breaks inline styles and the asset pipeline. Supply one through
 * `$overrides` (`['Content-Security-Policy' => "..."]`) when the application
 * is ready for it. HSTS is also left to the TLS terminator, because sending it
 * over plain HTTP would pin browsers to a host that cannot serve HTTPS.
 *
 * The middleware never overwrites a header the application already set.
 */
final class SecurityHeaders implements MiddlewareContract
{
    /** @var array<string, string> */
    private const DEFAULTS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
        'Cross-Origin-Opener-Policy' => 'same-origin',
    ];

    /**
     * @param array<string, string> $overrides extra or replacing headers
     */
    public function __construct(private readonly array $overrides = [])
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach ($this->overrides + self::DEFAULTS as $name => $value) {
            if ($response->header($name) === null) {
                $response = $response->withHeader($name, $value);
            }
        }

        return $response;
    }
}
