<?php

declare(strict_types=1);

namespace Sahil\Frontend;

/**
 * The requested frontend has no registered adapter.
 *
 * The message is actionable on purpose: it names the missing target and the
 * adapters that ARE registered, because the usual cause is a missing
 * starter-kit/provider registration, not a programming error.
 */
final class MissingFrontendAdapterException extends FrontendException
{
    /**
     * @param list<string> $available
     */
    public static function forTarget(string $target, array $available): self
    {
        sort($available);

        return new self(sprintf(
            'No frontend adapter is registered for [%s]. Registered adapters: %s. '
            .'Install the matching starter-kit (or register an adapter that implements %s).',
            $target,
            $available === [] ? 'none' : implode(', ', $available),
            FrontendAdapterContract::class,
        ));
    }
}
