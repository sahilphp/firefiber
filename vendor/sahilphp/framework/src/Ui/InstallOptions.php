<?php

declare(strict_types=1);

namespace Sahil\Ui;

/**
 * Options shared by `ui:install` and its callers.
 */
final class InstallOptions
{
    /**
     * @throws UiException when mutually exclusive modes are combined
     */
    public function __construct(
        public readonly bool $dryRun = false,
        public readonly bool $merge = false,
        public readonly bool $overwrite = false,
        public readonly bool $installDependencies = true,
    ) {
        if ($this->merge && $this->overwrite) {
            throw new UiException('Choose one conflict policy: --merge or --overwrite (not both).');
        }
    }

    public function hasConflictPolicy(): bool
    {
        return $this->merge || $this->overwrite;
    }
}
