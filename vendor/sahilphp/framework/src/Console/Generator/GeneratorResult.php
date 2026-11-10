<?php

declare(strict_types=1);

namespace Sahil\Console\Generator;

/**
 * Result of one generator run (a model can also produce a migration).
 */
final class GeneratorResult
{
    /**
     * @param list<GeneratedFile> $files
     */
    public function __construct(public readonly array $files)
    {
    }
}
