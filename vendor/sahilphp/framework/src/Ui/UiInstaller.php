<?php

declare(strict_types=1);

namespace Sahil\Ui;

use JsonException;

/**
 * Installs a frontend profile into a project directory.
 *
 * The installer never writes a file until it has built the complete plan and
 * checked every existing path. The default policy is to abort on conflicts;
 * `--merge` preserves user configuration and adds only what is missing, and
 * `--overwrite` replaces managed files after copying them to a backup.
 */
final class UiInstaller
{
    /** @var list<string> */
    private const GITIGNORE_ENTRIES = [
        '/node_modules/',
        '/public/build/',
        '/.vite/',
        '/storage/framework/vite/',
        '/storage/framework/ui-backups/',
    ];

    public function __construct(
        private readonly ProcessRunner $runner = new ProcessRunner(),
        private readonly ToolchainDetector $detector = new ToolchainDetector(),
    ) {
    }

    public function install(string $basePath, Profile $profile, InstallOptions $options): InstallReport
    {
        $basePath = $this->projectDirectory($basePath);
        $toolchain = $this->detector->detect($basePath);
        $report = new InstallReport($profile, $basePath, $options->dryRun);

        if ($options->installDependencies && !$options->dryRun && !$toolchain->isReady()) {
            throw new UiException(
                "Missing frontend prerequisites:\n  - "
                .implode("\n  - ", $toolchain->issues())
                ."\nUse --no-install to scaffold the files without installing dependencies.",
            );
        }

        if (!$options->installDependencies) {
            $report->notes[] = 'dependency installation skipped (--no-install); run the package manager install command when ready';
        }

        foreach ($toolchain->issues() as $issue) {
            $report->notes[] = $issue;
        }

        $plan = ProfilePlan::build($profile, $this->packageName($basePath));
        $actions = $this->planActions($basePath, $plan, $options);

        $conflicts = [];

        foreach ($actions as $relative => $action) {
            if ($action['kind'] === 'conflict') {
                $conflicts[] = $relative;
            }
        }

        if ($conflicts !== [] && !$options->hasConflictPolicy()) {
            throw new ConflictException($conflicts);
        }

        [$gitignoreContent, $gitignoreAction] = $this->gitignorePlan($basePath);
        $manifestContent = $this->manifest($plan);
        $manifestPath = $basePath.'/.sahil-ui.json';
        $manifestAction = is_file($manifestPath) ? 'update' : 'create';

        if ($options->dryRun) {
            $this->recordActions($report, $actions);
            $this->recordGitignore($report, $gitignoreAction, $gitignoreContent !== null);
            $this->recordManifest($report, $manifestAction);

            return $report;
        }

        // Apply file actions. Every overwritten existing file is backed up
        // first, including package.json changed by --overwrite.
        foreach ($actions as $relative => $action) {
            $path = $basePath.'/'.$relative;

            if ($action['kind'] === 'create') {
                $this->writeFile($path, $action['content']);
                $report->created[] = $relative;

                continue;
            }

            if ($action['kind'] === 'update') {
                if (is_file($path)) {
                    $report->backups[] = $this->backupFile($basePath, $relative);
                }

                $this->writeFile($path, $action['content']);
                $report->updated[] = $relative;

                continue;
            }

            if ($action['kind'] === 'preserved') {
                $report->preserved[] = $relative;
            }
        }

        if ($gitignoreContent !== null) {
            $this->writeFile($basePath.'/.gitignore', $gitignoreContent);
            $this->recordGitignore($report, $gitignoreAction, true);
        }

        $this->writeFile($manifestPath, $manifestContent);
        $this->recordManifest($report, $manifestAction);

        if ($options->installDependencies) {
            $command = [...$toolchain->command(), 'install'];
            $result = $this->runner->run($command, $basePath);
            $report->dependencyInstall = $result;

            if (!$result->successful()) {
                $details = trim($result->stderr) !== '' ? trim($result->stderr) : trim($result->stdout);

                throw new ProcessFailureException(
                    sprintf(
                        "Dependency installation failed (%s exited %d).\n%s\n"
                        .'Files were generated; fix the package manager error and run `bin/sahil ui:build`.',
                        $result->describe(),
                        $result->exitCode,
                        $this->tail($details),
                    ),
                    $result->exitCode > 0 ? $result->exitCode : 1,
                );
            }
        }

        return $report;
    }

    /**
     * @return array<string, array{kind: 'create'|'update'|'preserved'|'skip'|'conflict', content: string}>
     */
    private function planActions(string $basePath, ProfilePlan $plan, InstallOptions $options): array
    {
        $packagePath = $basePath.'/package.json';
        $existingPackageContent = is_file($packagePath) ? $this->readFile($packagePath) : null;
        $existingPackage = null;

        if ($existingPackageContent !== null) {
            try {
                $existingPackage = $this->decodePackage($existingPackageContent);
            } catch (UiException $invalid) {
                if (!$options->overwrite) {
                    throw new UiException(
                        $invalid->getMessage()
                        .' Use --overwrite to replace it (a backup is kept), or fix the JSON and re-run.',
                    );
                }
            }
        }

        $desiredPackage = $plan->package;
        $desiredContent = $plan->files['package.json'];
        $mergedPackage = $existingPackage === null
            ? $desiredPackage
            : PackageJsonMerger::merge($existingPackage, $desiredPackage);
        $mergedContent = ProfilePlan::encodePackage($mergedPackage);

        $actions = [];

        foreach ($plan->files as $relative => $content) {
            $path = $basePath.'/'.$relative;
            $exists = is_file($path);

            if (!$exists) {
                $actions[$relative] = ['kind' => 'create', 'content' => $content];

                continue;
            }

            $current = $this->readFile($path);

            if ($relative !== 'package.json') {
                $actions[$relative] = match (true) {
                    $current === $content => ['kind' => 'skip', 'content' => $content],
                    $options->overwrite => ['kind' => 'update', 'content' => $content],
                    $options->merge => ['kind' => 'preserved', 'content' => $content],
                    default => ['kind' => 'conflict', 'content' => $content],
                };

                continue;
            }

            if ($existingPackage === null) {
                // Invalid JSON handled above with --overwrite.
                $actions[$relative] = ['kind' => 'update', 'content' => $desiredContent];

                continue;
            }

            $changedByMerge = !PackageJsonMerger::equals($mergedPackage, $existingPackage);
            $changedByOverwrite = $desiredContent !== $current;

            $actions[$relative] = match (true) {
                $options->overwrite => $changedByOverwrite
                    ? ['kind' => 'update', 'content' => $desiredContent]
                    : ['kind' => 'skip', 'content' => $desiredContent],
                $options->merge => $changedByMerge
                    ? ['kind' => 'update', 'content' => $mergedContent]
                    : ['kind' => 'skip', 'content' => $mergedContent],
                $changedByMerge => ['kind' => 'conflict', 'content' => $mergedContent],
                default => ['kind' => 'skip', 'content' => $mergedContent],
            };
        }

        return $actions;
    }

    /**
     * @param array<string, array{kind: string, content: string}> $actions
     */
    private function recordActions(InstallReport $report, array $actions): void
    {
        foreach ($actions as $relative => $action) {
            match ($action['kind']) {
                'create' => $report->created[] = $relative,
                'update' => $report->updated[] = $relative,
                'preserved' => $report->preserved[] = $relative,
                default => null,
            };
        }
    }

    private function recordGitignore(InstallReport $report, ?string $action, bool $changed): void
    {
        if (!$changed || $action === null) {
            return;
        }

        if ($action === 'create') {
            $report->created[] = '.gitignore';
        } else {
            $report->updated[] = '.gitignore';
        }
    }

    private function recordManifest(InstallReport $report, string $action): void
    {
        if ($action === 'create') {
            $report->created[] = '.sahil-ui.json';
        } else {
            $report->updated[] = '.sahil-ui.json';
        }
    }

    /**
     * @return array{0: ?string, 1: ?string} content to write and action kind
     */
    private function gitignorePlan(string $basePath): array
    {
        $path = $basePath.'/.gitignore';
        $existing = is_file($path) ? $this->readFile($path) : null;
        $lines = $existing === null ? [] : preg_split('/\R/', $existing);

        if ($lines === false) {
            $lines = [];
        }

        $present = array_map(
            static fn (string $line): string => strtolower(ltrim(rtrim(trim($line), '/'), '/')),
            $lines,
        );
        $missing = [];

        foreach (self::GITIGNORE_ENTRIES as $entry) {
            $normalized = strtolower(ltrim(rtrim($entry, '/'), '/'));

            if (!in_array($normalized, $present, true)) {
                $missing[] = $entry;
            }
        }

        if ($missing === []) {
            return [null, null];
        }

        if ($existing === null) {
            return ["# Frontend dependencies and build output\n".implode("\n", $missing)."\n", 'create'];
        }

        $separator = str_ends_with($existing, "\n") || $existing === '' ? '' : "\n";

        return [$existing.$separator.implode("\n", $missing)."\n", 'update'];
    }

    private function manifest(ProfilePlan $plan): string
    {
        $manifest = [
            'profile' => $plan->profile->value,
            'generated_at' => gmdate(DATE_ATOM),
            'files' => array_keys($plan->files),
            'commands' => [
                'dev' => 'bin/sahil ui:dev',
                'build' => 'bin/sahil ui:build',
                'status' => 'bin/sahil ui:status',
            ],
        ];

        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw new UiException('Unable to encode the UI manifest.');
        }

        return $json."\n";
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePackage(string $content): array
    {
        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $invalid) {
            throw new UiException('package.json is not valid JSON: '.$invalid->getMessage());
        }

        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new UiException('package.json must contain a JSON object.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function packageName(string $basePath): string
    {
        $name = strtolower(basename($basePath));
        $name = (string) preg_replace('/[^a-z0-9._-]+/', '-', $name);
        $name = trim($name, '.-_');

        return $name === '' ? 'sahilphp-app' : $name;
    }

    private function projectDirectory(string $path): string
    {
        if (!is_dir($path)) {
            throw new UiException(sprintf('Project directory [%s] does not exist.', $path));
        }

        $resolved = realpath($path);

        if ($resolved === false) {
            throw new UiException(sprintf('Unable to resolve project directory [%s].', $path));
        }

        if (!is_writable($resolved)) {
            throw new UiException(sprintf('Project directory [%s] is not writable.', $resolved));
        }

        return $resolved;
    }

    private function readFile(string $path): string
    {
        $content = file_get_contents($path);

        if ($content === false) {
            throw new UiException(sprintf('Unable to read [%s].', $path));
        }

        return $content;
    }

    private function writeFile(string $path, string $content): void
    {
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new UiException(sprintf('Unable to create directory [%s].', $directory));
        }

        $temporary = $path.'.tmp-'.bin2hex(random_bytes(4));
        $written = file_put_contents($temporary, $content);

        if ($written === false || $written !== strlen($content)) {
            @unlink($temporary);

            throw new UiException(sprintf('Unable to write [%s].', $path));
        }

        if (!rename($temporary, $path)) {
            @unlink($temporary);

            throw new UiException(sprintf('Unable to replace [%s].', $path));
        }
    }

    private function backupFile(string $basePath, string $relative): string
    {
        $backupRoot = sprintf(
            'storage/framework/ui-backups/%s-%s',
            gmdate('Ymd-His'),
            bin2hex(random_bytes(3)),
        );
        $destination = $basePath.'/'.$backupRoot.'/'.$relative;

        if (!is_dir(dirname($destination)) && !mkdir(dirname($destination), 0o775, true) && !is_dir(dirname($destination))) {
            throw new UiException(sprintf('Unable to create backup directory [%s].', dirname($destination)));
        }

        if (!copy($basePath.'/'.$relative, $destination)) {
            throw new UiException(sprintf('Unable to back up [%s].', $relative));
        }

        return $backupRoot.'/'.$relative;
    }

    private function tail(string $text, int $limit = 4000): string
    {
        if (strlen($text) <= $limit) {
            return $text;
        }

        return '...'.substr($text, -$limit);
    }
}
