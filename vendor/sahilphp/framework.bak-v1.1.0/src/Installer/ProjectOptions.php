<?php

declare(strict_types=1);

namespace Sahil\Installer;

use Sahil\Ui\Profile;

/**
 * Inputs for a single project-creation run.
 */
final class ProjectOptions
{
    public function __construct(
        public readonly string $sourcePath,
        public readonly string $destination,
        public readonly string $projectName,
        public readonly Profile $ui,
        public readonly bool $installDependencies = true,
        public readonly bool $installDevDependencies = true,
    ) {
    }
}
