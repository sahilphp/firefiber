<?php

declare(strict_types=1);

namespace Sahil\Firefiber\Console\Commands;

use Sahil\Console\Command;
use Sahil\Console\CommandInput;
use Sahil\Console\CommandOutput;
use Sahil\Contracts\Storage\StorageContract;

/**
 * Delete staged upload directories whose lifetime has lapsed.
 *
 * Upload sidecar records expire through the state store's TTL, but the
 * files they pointed at — `firefiber/uploads/<id>/` — would otherwise be
 * orphaned. This command sweeps the staging directory on each configured
 * upload disk and removes directories older than `--max-age` seconds
 * (default: the 1-hour staged lifetime).
 *
 * Run it from a scheduler/cron entry, e.g. hourly:
 *
 *     php sahil firefiber:cleanup-uploads
 */
final class FirefiberUploadsCleanupCommand extends Command
{
    private const STAGING_PREFIX = 'firefiber/uploads';

    public function name(): string
    {
        return 'firefiber:cleanup-uploads';
    }

    public function description(): string
    {
        return 'Remove expired temporary Firefiber uploads.';
    }

    public function usage(): string
    {
        return 'php sahil firefiber:cleanup-uploads [--max-age=SECONDS] [--disk=NAME]';
    }

    public function category(): string
    {
        return 'Maintenance';
    }

    public function optionSpecs(): array
    {
        return [
            'max-age' => 'SECONDS',
            'disk' => 'NAME',
        ];
    }

    /**
     * @return list<string>
     */
    public function optionHelp(): array
    {
        return [
            '--max-age=SECONDS  Minimum age before a staged upload directory is removed (default: 3600).',
            '--disk=NAME        Only clean one storage disk (default: every configured disk).',
        ];
    }

    public function examples(): array
    {
        return [
            'php sahil firefiber:cleanup-uploads',
            'php sahil firefiber:cleanup-uploads --max-age=7200 --disk=local',
        ];
    }

    public function execute(CommandInput $input, CommandOutput $output): int
    {
        $maxAge = max(60, (int) ($input->option('max-age') ?? 3600));
        $cutoff = time() - $maxAge;

        $container = $this->app()->container();
        if (!$container->bound(StorageContract::class)) {
            $output->line('No storage service is bound; nothing to clean.');

            return 0;
        }

        $storage = $container->make(StorageContract::class);
        $onlyDisk = $input->option('disk');
        $disks = $onlyDisk !== null && $onlyDisk !== ''
            ? [(string) $onlyDisk]
            : $this->configuredDisks();

        $removed = 0;

        foreach ($disks as $disk) {
            foreach ($storage->listContents(self::STAGING_PREFIX, $disk) as $entry) {
                if (!is_array($entry) || ($entry['type'] ?? null) !== 'dir') {
                    continue;
                }

                $path = trim((string) ($entry['path'] ?? ''), '/');

                // Only top-level upload-id directories are swept; anything
                // else under the prefix is left alone.
                if ($path === '' || substr_count($path, '/') !== 2) {
                    continue;
                }

                $modified = (int) ($entry['last_modified'] ?? 0);

                if ($modified <= 0) {
                    try {
                        $modified = $storage->lastModified($path, $disk);
                    } catch (\Throwable) {
                        continue;
                    }
                }

                if ($modified > 0 && $modified <= $cutoff) {
                    $storage->deleteDirectory($path, $disk);
                    $removed++;
                }
            }
        }

        $output->line(sprintf('Removed %d expired upload director%s.', $removed, $removed === 1 ? 'y' : 'ies'));

        return 0;
    }

    /**
     * @return list<string>
     */
    private function configuredDisks(): array
    {
        $disks = $this->app()->config()->get('filesystems.disks', ['local' => []]);

        if (!is_array($disks) || $disks === []) {
            return ['local'];
        }

        return array_map('strval', array_keys($disks));
    }
}
