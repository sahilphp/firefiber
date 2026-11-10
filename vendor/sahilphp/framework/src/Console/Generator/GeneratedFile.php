<?php

declare(strict_types=1);

namespace Sahil\Console\Generator;

/**
 * The file a generator produced.
 */
final class GeneratedFile
{
    public function __construct(
        public readonly string $path,
        public readonly string $relativePath,
        public readonly ?string $namespace,
        public readonly string $className,
        public readonly ?string $backupPath = null,
    ) {
    }
}
