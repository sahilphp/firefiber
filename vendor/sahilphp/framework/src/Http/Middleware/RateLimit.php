<?php

declare(strict_types=1);

namespace Sahil\Http\Middleware;

use Sahil\Http\RateLimit\RateLimiter;

/**
 * Middleware attribute for a shared rate-limit budget applied once per
 * route group.
 *
 * When several routes must share a single budget (e.g. a public contact
 * form or a batch endpoint), apply this attribute to the group and the
 * budget is enforced by one `RateLimiter` instance across every route in
 * the group. Routes registered individually with `Throttle` keep their
 * own private budget.
 */
final class RateLimit
{
    public function __construct(
        private readonly string $key,
        private readonly ?int $limit = null,
        private readonly ?int $window = null,
    ) {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function limit(): ?int
    {
        return $this->limit;
    }

    public function window(): ?int
    {
        return $this->window;
    }
}
