<?php

declare(strict_types=1);

namespace Sahil\Container;

use Sahil\Contracts\Container\ScopeContract;

/**
 * One lifecycle run (normally: one HTTP request, or one worker daemon
 * iteration). Instances of scoped bindings are recreated per scope and
 * dropped entirely defensively when {@see end()} is called, which the
 * framework runs inside `finally` blocks so services never outlive a
 * failed request.
 */
final class Scope implements ScopeContract
{
    private static int $counter = 0;

    private string $id;

    private bool $ended = false;

    private Container $container;

    public function __construct(Container $container)
    {
        self::$counter++;
        $this->id = 'scope-'.self::$counter;
        $this->container = $container;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function end(): void
    {
        if ($this->ended) {
            return;
        }

        $this->ended = true;
        $this->container->endScope($this);
    }

    public function isEnded(): bool
    {
        return $this->ended;
    }

    public function __destruct()
    {
        if (!$this->ended) {
            // GC fallback: normal lifecycle paths drop scopes end() in
            // a finally block; reaching the destructor means that path
            // was skipped and the container may still hold this scope.
            error_log(
                sprintf('SahilPHP: scope [%s] was garbage collected without end() being called.', $this->id),
            );
        }
    }
}
