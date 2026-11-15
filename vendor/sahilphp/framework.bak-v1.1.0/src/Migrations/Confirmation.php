<?php

declare(strict_types=1);

namespace Sahil\Migrations;

/**
 * The default confirmation policy: interactive when the CLI has a terminal,
 * refusing otherwise.
 *
 * Reads the answer from STDIN and requires an explicit `yes`. When STDIN is
 * not a terminal (CI, cron, a piped command) it answers `false` WITHOUT
 * prompting, so an unattended run can never confirm a destructive operation
 * by accident — such runs must pass the documented force flag instead.
 */
final class Confirmation implements ConfirmationContract
{
    public function confirm(string $question): bool
    {
        if (!self::isInteractive()) {
            return false;
        }

        fwrite(STDOUT, sprintf('%s [yes/no]: ', $question));

        $answer = fgets(STDIN);

        if ($answer === false) {
            return false;
        }

        return strtolower(trim($answer)) === 'yes';
    }

    private static function isInteractive(): bool
    {
        if (!defined('STDIN')) {
            return false;
        }

        return function_exists('stream_isatty') && @stream_isatty(STDIN);
    }
}
