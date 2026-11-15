<?php

declare(strict_types=1);

namespace Sahil\Theme;

use Sahil\Ui\Profile;

/**
 * The result of planning or applying a theme generation run.
 */
final class ThemeReport
{
    /**
     * @param list<string> $created
     * @param list<string> $updated
     * @param list<string> $preserved
     * @param list<string> $skipped
     */
    public function __construct(
        public readonly Profile $profile,
        public readonly string $theme,
        public readonly string $source,
        public readonly bool $dryRun,
        public readonly bool $sourceCreated,
        /** @var list<string> */
        public array $created = [],
        /** @var list<string> */
        public array $updated = [],
        /** @var list<string> */
        public array $preserved = [],
        /** @var list<string> */
        public array $skipped = [],
    ) {
    }

    /**
     * @return list<string>
     */
    public function lines(): array
    {
        $lines = [
            'UI theme: '.$this->theme,
            'Profile: '.$this->profile->label(),
            'Source: '.$this->source.($this->sourceCreated ? ($this->dryRun ? ' (would create)' : ' (created)') : ''),
        ];

        if ($this->dryRun) {
            $lines[] = 'Mode: dry run (nothing was written)';
        }

        foreach ($this->created as $file) {
            $lines[] = ($this->dryRun ? 'would create ' : 'create     ').$file;
        }

        foreach ($this->updated as $file) {
            $lines[] = ($this->dryRun ? 'would update ' : 'update     ').$file;
        }

        foreach ($this->preserved as $file) {
            $lines[] = 'preserved  '.$file;
        }

        foreach ($this->skipped as $file) {
            $lines[] = 'unchanged  '.$file;
        }

        return $lines;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'theme' => $this->theme,
            'profile' => $this->profile->value,
            'profile_label' => $this->profile->label(),
            'source' => $this->source,
            'source_created' => $this->sourceCreated,
            'dry_run' => $this->dryRun,
            'created' => $this->created,
            'updated' => $this->updated,
            'preserved' => $this->preserved,
            'skipped' => $this->skipped,
        ];
    }
}
