<?php

declare(strict_types=1);

namespace Sahil\Http;

use Sahil\Routing\Link;

/**
 * Result of a successful router lookup: the matched route definition and its
 * path parameters (already URL-decoded).
 */
final class RouteMatch
{
    /**
     * @param array<string, string> $params decoded parameter values
     */
    public function __construct(
        private readonly Link $route,
        private readonly array $params = [],
    ) {
    }

    public function route(): Link
    {
        return $this->route;
    }

    /**
     * @return array<string, string>
     */
    public function params(): array
    {
        return $this->params;
    }
}
