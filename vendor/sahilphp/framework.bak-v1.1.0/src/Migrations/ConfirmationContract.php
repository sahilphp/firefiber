<?php

declare(strict_types=1);

namespace Sahil\Migrations;

/**
 * How a destructive operation asks the operator to confirm.
 *
 * Abstracted so the safety decision is testable and so non-interactive
 * callers (CI, supervisors) can supply an explicit policy instead of
 * blocking on a prompt that will never be answered.
 */
interface ConfirmationContract
{
    /**
     * @return bool true when the operator deliberately confirmed
     */
    public function confirm(string $question): bool;
}
