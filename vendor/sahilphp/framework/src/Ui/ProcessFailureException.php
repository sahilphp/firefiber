<?php

declare(strict_types=1);

namespace Sahil\Ui;

/**
 * An external process exited nonzero. The original exit code is preserved so
 * CLI callers can propagate it unchanged.
 */
final class ProcessFailureException extends UiException
{
    public function __construct(
        string $message,
        public readonly int $processExitCode,
    ) {
        parent::__construct($message);
    }
}
