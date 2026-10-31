<?php

declare(strict_types=1);

namespace Sahil\Migrations;

/**
 * Never confirms. The safe default for unattended callers (tests, CI,
 * schedulers): a destructive operation must then be authorised explicitly
 * through the force flag, never implicitly.
 */
final class RefusingConfirmation implements ConfirmationContract
{
    public function confirm(string $question): bool
    {
        return false;
    }
}
