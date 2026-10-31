<?php

declare(strict_types=1);

namespace Sahil\Contracts\Foundation;

use Sahil\Config\Repository;
use Sahil\Container\Container;
use Sahil\Contracts\Container\ScopeContract;
use Sahil\Support\Environment;

/**
 * Stable application kernel surface. Implemented by
 * Sahil\Foundation\Application; kept as a contract so applications and
 * future phases can type-hint against the kernel without coupling to the
 * concrete implementation.
 */
interface ApplicationContract
{
    public function basePath(string $sub = ''): string;

    public function container(): Container;

    public function config(): Repository;

    public function environment(): Environment;

    public function isBooted(): bool;

    public function boot(): void;

    /**
     * Drop request-scoped container state so this process can serve the
     * next iteration cleanly (worker safety).
     */
    public function reset(): void;

    /**
     * Open a request scope and execute `$work` inside it, ending the scope
     * in a `finally` block so scoped instances are dropped even when
     * `$work` throws.
     *
     * @param callable(ScopeContract $scope): mixed $work
     */
    public function isolatedRequest(callable $work): mixed;
}
