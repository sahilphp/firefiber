<?php

declare(strict_types=1);

namespace Sahil\Support\Logging;

/**
 * Optional logger capability for pre-structured events.
 *
 * The generic {@see LoggerContract} envelope is level/message/context.
 * Request logging needs a fixed event shape instead, so a logger that can
 * persist that shape directly may implement this contract. Callers should
 * fall back to {@see LoggerContract::log()} when it is not available.
 */
interface StructuredLoggerContract extends LoggerContract
{
    /**
     * Write one complete structured event without adding the generic
     * level/message/context envelope.
     *
     * @param array<string, mixed> $event
     */
    public function logEvent(array $event): void;
}
