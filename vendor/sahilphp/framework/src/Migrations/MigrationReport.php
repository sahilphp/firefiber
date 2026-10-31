<?php

declare(strict_types=1);

namespace Sahil\Migrations;

/**
 * Outcome of one migration/seed command.
 *
 * A predictable value object rather than console side effects: commands
 * decide how to render it, and tests assert on it directly.
 */
final class MigrationReport
{
    /**
     * @param list<string> $applied   migrations/seeds that ran
     * @param list<string> $rolledBack entries reversed
     * @param list<string> $pending    discovered but not applied
     * @param list<string> $skipped    deliberately not run (irreversible, already run…)
     * @param list<string> $notes      operator-facing context lines
     */
    public function __construct(
        public readonly string $command,
        public readonly array $applied = [],
        public readonly array $rolledBack = [],
        public readonly array $pending = [],
        public readonly array $skipped = [],
        public readonly array $notes = [],
    ) {
    }

    /**
     * Whether anything actually changed the database.
     */
    public function changed(): bool
    {
        return $this->applied !== [] || $this->rolledBack !== [];
    }

    /**
     * Human-readable lines for the console.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        $lines = [];

        foreach ($this->applied as $name) {
            $lines[] = sprintf('  applied      %s', $name);
        }

        foreach ($this->rolledBack as $name) {
            $lines[] = sprintf('  rolled back  %s', $name);
        }

        foreach ($this->skipped as $name) {
            $lines[] = sprintf('  skipped      %s', $name);
        }

        foreach ($this->pending as $name) {
            $lines[] = sprintf('  pending      %s', $name);
        }

        foreach ($this->notes as $note) {
            $lines[] = '  '.$note;
        }

        return $lines;
    }
}
