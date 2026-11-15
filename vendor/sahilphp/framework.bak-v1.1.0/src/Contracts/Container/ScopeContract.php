<?php

declare(strict_types=1);

namespace Sahil\Contracts\Container;

/**
 * One lifecycle run: normally one HTTP request, or one long-running
 * worker iteration. Implemented by Sahil\Container\Scope.
 */
interface ScopeContract
{
    /**
     * Unique, process-stable identifier of this scope (monotonic).
     */
    public function id(): string;

    /**
     * Close the scope: every instance resolved while it was active is
     * discarded. Callers should wrap scope usage in try/finally so end()
     * always runs.
     */
    public function end(): void;

    /**
     * Whether this scope has already been closed (end() ran).
     */
    public function isEnded(): bool;
}
