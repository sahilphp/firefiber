<?php

declare(strict_types=1);

namespace Sahil\Http;

use Sahil\Routing\Link;

/**
 * Backwards-compatible alias for {@see Link}, the native route-definition
 * API.
 *
 * Both names produce the same immutable definitions consumed by the same
 * {@see Router}: `Route::get()` returns a `Route` (which is a `Link`), and
 * `Link::get()` returns a `Link`. Nothing is duplicated and no separate
 * router exists — this class only preserves the historical
 * `Sahil\Http\Route` name for applications written before `Link` existed.
 *
 * @deprecated Use {@see \Sahil\Routing\Link} for all new route definitions.
 */
final class Route extends Link
{
}
