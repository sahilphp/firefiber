<?php

declare(strict_types=1);

namespace Sahil\Ui;

/**
 * One or more files already exist with content different from what the
 * selected profile would write.
 *
 * Nothing has been written when this exception is thrown; the caller must
 * choose `--merge` or `--overwrite` explicitly.
 */
final class ConflictException extends UiException
{
    /**
     * @param list<string> $conflicts project-relative paths
     */
    public function __construct(
        public readonly array $conflicts,
        string $message = '',
    ) {
        if ($message === '') {
            $message = sprintf(
                '%d existing file(s) differ from the selected profile: %s',
                count($conflicts),
                implode(', ', $conflicts),
            );
        }

        parent::__construct($message);
    }
}
