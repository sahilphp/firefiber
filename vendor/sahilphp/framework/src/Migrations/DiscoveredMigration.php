<?php

declare(strict_types=1);

namespace Sahil\Migrations;

/**
 * A migration loaded from disk, tagged with the file it came from.
 *
 * `$name` is the file name without the `.php` extension — the timestamp
 * prefix makes the name sort chronologically, which is also the execution
 * order.
 */
final class DiscoveredMigration
{
    public function __construct(
        public readonly string $name,
        public readonly string $path,
        public readonly Migration $migration,
    ) {
    }
}
