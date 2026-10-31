<?php

declare(strict_types=1);

namespace Sahil\Ui;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Sahil\Theme\ThemeException;
use Sahil\Theme\ThemeInstaller;
use SplFileInfo;
use Throwable;

/**
 * CLI surface for UI installation and assets.
 *
 * Commands are intentionally usable before the application boots: they only
 * need the project directory, not configuration or a database connection.
 */
final class UiConsole
{
    /** @var list<string> */
    public const COMMANDS = ['ui:install', 'ui:dev', 'ui:build', 'ui:status', 'ui:theme'];

    public function __construct(
        private readonly ?UiInstaller $installer = null,
        private readonly ?UiStatus $status = null,
        private readonly ?ProcessRunner $runner = null,
        private readonly ?ThemeInstaller $themeInstaller = null,
    ) {
    }

    /**
     * @param list<string> $args arguments after the command name
     */
    public function run(string $command, array $args, string $basePath): int
    {
        try {
            $options = $this->parseOptions($args, $command);
            $projectPath = $this->projectPath($basePath, $options['path']);

            return match ($command) {
                'ui:install' => $this->install($projectPath, $options),
                'ui:dev' => $this->dev($projectPath, $options),
                'ui:build' => $this->build($projectPath, $options),
                'ui:status' => $this->status($projectPath, $options),
                'ui:theme' => $this->theme($projectPath, $options),
                default => throw new UiException(sprintf('Unknown UI command [%s].', $command)),
            };
        } catch (ConflictException $conflict) {
            $this->error(sprintf('%s: %s', $command, $conflict->getMessage()));
            $this->error('Nothing was written.');

            foreach ($conflict->conflicts as $file) {
                $this->error('  - '.$file);
            }

            $this->error('Choose a conflict policy and re-run:');
            $this->error('  --merge      keep existing files, merge package.json and .gitignore');
            $this->error('  --overwrite  replace managed files (originals are backed up)');

            return 1;
        } catch (ProcessFailureException $failure) {
            $this->error(sprintf('%s: %s', $command, $failure->getMessage()));

            return $failure->processExitCode;
        } catch (UiException|ThemeException $failure) {
            $this->error(sprintf('%s: %s', $command, $failure->getMessage()));

            return 1;
        } catch (Throwable $unexpected) {
            $this->error(sprintf('%s: %s', $command, $unexpected->getMessage()));

            if (getenv('SAHIL_DEBUG') === '1') {
                $this->error($unexpected::class);
            }

            return 1;
        }
    }

    /**
     * @param array{positional: list<string>, path: ?string, profile: ?string, source: ?string, dry_run: bool, merge: bool, overwrite: bool, no_install: bool, json: bool, clean: bool, host: ?string, port: ?string} $options
     */
    private function install(string $basePath, array $options): int
    {
        if (count($options['positional']) > 1) {
            throw new UiException(sprintf(
                'ui:install: unexpected argument [%s].',
                implode(' ', array_slice($options['positional'], 1)),
            ));
        }

        $explicit = $options['profile'] ?? null;
        $positional = $options['positional'][0] ?? null;

        // Interactive prompt when no profile is selected and a TTY is available.
        if ($explicit === null && $positional === null && !($options['no_interaction'] ?? false) && $this->isInteractive()) {
            $positional = $this->promptInstallProfile();
        }

        $profile = $this->resolveProfile($basePath, $explicit, $positional);

        // Stubs-based install: copy only the chosen UI's files, then remove
        // the stubs so the unchosen UI can never be installed from this project.
        $stubDir = $basePath.'/stubs/ui/'.$profile->value;

        if (($profile === Profile::Tailwind || $profile === Profile::Bootstrap) && is_dir($stubDir)) {
            return $this->installFromStubs($basePath, $profile, $options);
        }

        // Generation-based fallback (used when no UI stubs ship with the
        // project, e.g. in framework test fixtures or older installations).
        $installOptions = new InstallOptions(
            dryRun: $options['dry_run'],
            merge: $options['merge'],
            overwrite: $options['overwrite'],
            installDependencies: !$options['no_install'],
        );

        $report = $this->installer()->install($basePath, $profile, $installOptions);

        foreach ($report->lines() as $line) {
            $this->line($line);
        }

        return 0;
    }

    /**
     * Prompt the user to pick a UI framework when none was provided.
     */
    private function promptInstallProfile(): ?string
    {
        $this->line('Choose your UI framework:');
        $this->line('  1. Tailwind CSS');
        $this->line('  2. Bootstrap 5');
        $this->line();

        $answer = $this->readPrompt('Select UI framework [1-2]: ');

        if ($answer === null) {
            return null;
        }

        $answer = strtolower(trim($answer));

        if ($answer === '1' || $answer === 'tailwind' || $answer === 'tailwindcss') {
            return 'tailwind';
        }

        if ($answer === '2' || $answer === 'bootstrap' || $answer === 'bootstrap5') {
            return 'bootstrap';
        }

        throw new UiException(sprintf(
            'Invalid choice [%s]. Enter 1 for Tailwind CSS or 2 for Bootstrap 5.',
            $answer,
        ));
    }

    /**
     * Install a UI profile from the project's `stubs/ui/<profile>/` tree.
     *
     * Views land in `resources/views`, assets in `resources/css` and
     * `resources/js`, the manifest and Vite config at the project root. The
     * whole `stubs/ui` tree is deleted afterwards so no trace of the
     * unchosen UI remains.
     */
    private function installFromStubs(string $basePath, Profile $profile, array $options): int
    {
        $stubDir = $basePath.'/stubs/ui/'.$profile->value;

        $this->copyStubDirectory($stubDir.'/resources/views', $basePath.'/resources/views');
        $this->copyStubDirectory($stubDir.'/resources/css', $basePath.'/resources/css');
        $this->copyStubDirectory($stubDir.'/resources/js', $basePath.'/resources/js');

        foreach (['package.json', 'vite.config.js'] as $rootFile) {
            $source = $stubDir.'/'.$rootFile;
            $destination = $basePath.'/'.$rootFile;

            if (is_file($source)) {
                $this->copyFile($source, $destination);
                $this->line(sprintf('  write   %s', $rootFile));
            }
        }

        $this->writeUiManifest($basePath, $profile);

        $this->removeStubTree($basePath.'/stubs');

        $this->line();
        $this->line(sprintf('UI framework [%s] installed.', $profile->value));
        $this->line('  views   → resources/views');
        $this->line('  css     → resources/css');
        $this->line('  js      → resources/js');
        $this->line();
        $this->line('Next steps:');
        $this->line('  npm install            # install frontend dependencies');
        $this->line('  bin/sahil ui:build     # build production assets');

        if (!$options['no_install'] && $this->hasNode()) {
            $result = $this->runner()->run(['npm', 'install'], $basePath);

            if (!$result->successful()) {
                $this->emitProcessOutput($result);

                return 1;
            }

            $this->emitProcessOutput($result);
            $this->line('Dependencies installed. Run `bin/sahil ui:build` to build assets.');
        } elseif (!$options['no_install']) {
            $this->line('Node.js was not found; skipping `npm install`. Run it manually when Node is available.');
        }

        return 0;
    }

    /**
     * @param array{positional: list<string>, path: ?string, profile: ?string, source: ?string, dry_run: bool, merge: bool, overwrite: bool, no_install: bool, json: bool, clean: bool, host: ?string, port: ?string} $options
     */
    private function build(string $basePath, array $options): int
    {
        $this->rejectPositional($options, 'ui:build');

        $profile = $this->resolveProfile($basePath, $options['profile'], null);
        $toolchain = $this->readyToolchain($basePath, $options['dry_run']);
        $script = $this->packageScript($basePath, 'build');

        if ($script === null) {
            throw new UiException(
                'package.json has no "build" script. Run `bin/sahil ui:install --merge` '
                .'to add the generated scripts (existing scripts are preserved).',
            );
        }

        if (!$options['dry_run']) {
            $this->ensureDependencies($basePath, $toolchain);
        }

        if ($options['clean']) {
            $this->line($options['dry_run']
                ? 'would remove public/build'
                : 'removing public/build');

            if (!$options['dry_run']) {
                $this->removeBuildDirectory($basePath);
            }
        }

        $command = [...$this->managerCommand($toolchain, $options['dry_run']), 'run', 'build'];

        if ($options['dry_run']) {
            $this->line('would run: '.$this->describe($command));

            return 0;
        }

        $this->line('building UI assets ('.$profile->label().') with '.$this->describe($command));
        $result = $this->runner()->run($command, $basePath, ['NODE_ENV' => 'production']);

        $this->emitProcessOutput($result);

        if (!$result->successful()) {
            $this->error(sprintf(
                'ui:build: asset build failed (%s exited %d).',
                $result->describe(),
                $result->exitCode,
            ));

            return $result->exitCode > 0 ? $result->exitCode : 1;
        }

        $missing = [];

        foreach ($profile->expectedAssets() as $asset) {
            if (!is_file($basePath.'/'.$asset)) {
                $missing[] = $asset;
            }
        }

        if ($missing !== []) {
            $this->error('ui:build: the build command succeeded but expected output is missing:');
            $this->error('  - '.implode("\n  - ", $missing));
            $this->error('If you customised vite.config.js, check that its output names match the profile.');

            return 1;
        }

        $this->line('built:');

        foreach ($profile->expectedAssets() as $asset) {
            $this->line(sprintf('  %s (%s)', $asset, $this->humanBytes((int) filesize($basePath.'/'.$asset))));
        }

        return 0;
    }

    /**
     * @param array{positional: list<string>, path: ?string, profile: ?string, source: ?string, dry_run: bool, merge: bool, overwrite: bool, no_install: bool, json: bool, clean: bool, host: ?string, port: ?string} $options
     */
    private function dev(string $basePath, array $options): int
    {
        $this->rejectPositional($options, 'ui:dev');

        $profile = $this->resolveProfile($basePath, $options['profile'], null);
        $toolchain = $this->readyToolchain($basePath, $options['dry_run']);
        $script = $this->packageScript($basePath, 'dev');

        if ($script === null) {
            throw new UiException(
                'package.json has no "dev" script. Run `bin/sahil ui:install --merge` '
                .'to add the generated scripts (existing scripts are preserved).',
            );
        }

        $host = $options['host'] ?? '127.0.0.1';
        $requestedPort = $options['port'] !== null ? (int) $options['port'] : 5173;
        $port = $this->availableDevPort($host, $requestedPort);
        $hotUrl = $this->devServerUrl($host, $port);

        if ($port !== $requestedPort) {
            $this->line(sprintf(
                'ui:dev: %s:%d is already in use; using %d instead.',
                $host,
                $requestedPort,
                $port,
            ));
        }

        // `--strictPort` keeps Vite on the port selected above, so the URL
        // written to `public/hot` is always the URL the browser must use.
        $command = [
            ...$this->managerCommand($toolchain, $options['dry_run']),
            'run',
            'dev',
            '--',
            '--host='.$host,
            '--port='.$port,
            '--strictPort',
        ];

        if ($options['dry_run']) {
            $this->line('would run: '.$this->describe($command));
            $this->line('would write public/hot: '.$hotUrl);

            return 0;
        }

        $this->ensureDependencies($basePath, $toolchain);

        // Templates follow `public/hot`, so a port that moved still reaches
        // the running Vite server without a PHP restart or config edit.
        $hotFile = $basePath.'/public/hot';
        $this->writeHotFile($basePath, $hotUrl);

        $cleanup = static function () use ($hotFile): void {
            if (is_file($hotFile)) {
                @unlink($hotFile);
            }
        };
        register_shutdown_function($cleanup);

        // Ctrl+C stops the whole foreground process group, including this
        // wrapper, so shutdown functions alone would leave a stale pointer to
        // a Vite server that is gone.
        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);

            foreach ([SIGINT, SIGTERM] as $signal) {
                pcntl_signal($signal, static function () use ($cleanup): void {
                    $cleanup();
                    exit(0);
                });
            }
        }

        $this->line('starting UI development server ('.$profile->label().') with '.$this->describe($command));
        $this->line('  assets: '.$hotUrl.'  (written to public/hot)');
        $this->line('press Ctrl+C to stop');

        try {
            $result = $this->runner()->runInteractive($command, $basePath);
        } finally {
            $cleanup();
        }

        return $result->exitCode === 0 ? 0 : ($result->exitCode > 0 ? $result->exitCode : 1);
    }

    /**
     * First free TCP port at or above the requested one, so a busy 5173 does
     * not stop the frontend toolchain.
     */
    private function availableDevPort(string $host, int $port): int
    {
        $bindHost = str_contains($host, ':') && !str_starts_with($host, '[') ? '['.$host.']' : $host;

        for ($candidate = $port; $candidate <= min(65535, $port + 100); $candidate++) {
            $probe = @stream_socket_server('tcp://'.$bindHost.':'.$candidate);

            if ($probe !== false) {
                fclose($probe);

                return $candidate;
            }
        }

        throw new UiException(sprintf(
            'no free port was found in the next 100 above %d; stop the process using it or pass --port=<another-port>.',
            $port,
        ));
    }

    /**
     * Browsers cannot open `0.0.0.0`, so wildcard binds advertise loopback.
     */
    private function devServerUrl(string $host, int $port): string
    {
        $host = in_array($host, ['0.0.0.0', '::', '[::]'], true) ? '127.0.0.1' : $host;

        return 'http://'.$host.':'.$port;
    }

    private function writeHotFile(string $basePath, string $url): void
    {
        $path = $basePath.'/public/hot';
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new UiException(sprintf('Unable to create directory [%s].', $directory));
        }

        if (file_put_contents($path, $url."\n") === false) {
            throw new UiException(sprintf('Unable to write [%s].', $path));
        }
    }

    /**
     * @param array{positional: list<string>, path: ?string, profile: ?string, source: ?string, dry_run: bool, merge: bool, overwrite: bool, no_install: bool, json: bool, clean: bool, host: ?string, port: ?string} $options
     */
    private function status(string $basePath, array $options): int
    {
        $this->rejectPositional($options, 'ui:status');

        $report = $this->statusInspector()->inspect($basePath);

        if ($options['json']) {
            $json = json_encode(
                $report->toArray(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );

            if ($json === false) {
                throw new UiException('Unable to encode the status report as JSON.');
            }

            $this->line($json);

            return $report->ok() ? 0 : 1;
        }

        foreach ($report->lines() as $line) {
            $this->line($line);
        }

        return $report->ok() ? 0 : 1;
    }

    /**
     * @param array{positional: list<string>, path: ?string, profile: ?string, source: ?string, dry_run: bool, merge: bool, overwrite: bool, no_install: bool, json: bool, clean: bool, host: ?string, port: ?string} $options
     */
    private function theme(string $basePath, array $options): int
    {
        if (count($options['positional']) > 1) {
            throw new UiException(sprintf(
                'ui:theme: unexpected argument [%s].',
                implode(' ', array_slice($options['positional'], 1)),
            ));
        }

        $profile = $this->resolveProfile($basePath, $options['profile'], $options['positional'][0] ?? null);
        $report = $this->themeInstaller()->install(
            $basePath,
            $profile,
            $options['source'],
            $options['dry_run'],
        );

        if ($options['json']) {
            $json = json_encode(
                $report->toArray(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );

            if ($json === false) {
                throw new UiException('Unable to encode the theme report as JSON.');
            }

            $this->line($json);

            return 0;
        }

        foreach ($report->lines() as $line) {
            $this->line($line);
        }

        return 0;
    }

    /**
     * @param list<string> $args
     *
     * @return array{positional: list<string>, path: ?string, profile: ?string, source: ?string, dry_run: bool, merge: bool, overwrite: bool, no_install: bool, json: bool, clean: bool, host: ?string, port: ?string}
     */
    private function parseOptions(array $args, string $command): array
    {
        $options = [
            'positional' => [],
            'path' => null,
            'profile' => null,
            'source' => null,
            'dry_run' => false,
            'merge' => false,
            'overwrite' => false,
            'no_install' => false,
            'json' => false,
            'clean' => false,
            'host' => null,
            'port' => null,
        ];

        for ($index = 0; $index < count($args); $index++) {
            $arg = $args[$index];

            if (!str_starts_with($arg, '--')) {
                $options['positional'][] = $arg;

                continue;
            }

            $name = $arg;
            $value = null;

            if (str_contains($arg, '=')) {
                [$name, $value] = explode('=', $arg, 2);
            }

            switch ($name) {
                case '--dry-run':
                    $options['dry_run'] = true;

                    break;
                case '--merge':
                    $options['merge'] = true;

                    break;
                case '--overwrite':
                case '--force':
                    $options['overwrite'] = true;

                    break;
                case '--no-install':
                    $options['no_install'] = true;

                    break;
                case '--json':
                    $options['json'] = true;

                    break;
                case '--clean':
                    $options['clean'] = true;

                    break;
                case '--path':
                case '--profile':
                case '--ui':
                case '--source':
                case '--host':
                case '--port':
                    if ($value === null) {
                        $next = $args[$index + 1] ?? null;

                        if ($next === null || str_starts_with($next, '--')) {
                            throw new UiException(sprintf('%s: %s requires a value.', $command, $name));
                        }

                        $value = $next;
                        $index++;
                    }

                    $key = $name === '--ui' ? 'profile' : substr($name, 2);
                    $value = trim($value);

                    if ($value === '') {
                        throw new UiException(sprintf('%s: %s requires a non-empty value.', $command, $name));
                    }

                    $options[$key] = $value;

                    break;
                case '--no-interaction':
                    $options['no_interaction'] = true;

                    break;
                case '--interactive':
                    $options['no_interaction'] = false;

                    break;
                default:
                    throw new UiException(sprintf('%s: unknown option [%s].', $command, $name));
            }
        }

        if ($options['port'] !== null) {
            $port = filter_var($options['port'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);

            if ($port === false) {
                throw new UiException('--port must be an integer between 1 and 65535.');
            }

            $options['port'] = (string) $port;
        }

        return $options;
    }

    private function resolveProfile(string $basePath, ?string $explicit, ?string $positional): Profile
    {
        if ($explicit !== null && $positional !== null) {
            $explicitProfile = Profile::resolve($explicit);
            $positionalProfile = Profile::resolve($positional);

            if ($explicitProfile !== $positionalProfile) {
                throw new UiException(sprintf(
                    'Conflicting profiles [%s] and [%s]. Pass only one.',
                    $positional,
                    $explicit,
                ));
            }
        }

        $name = $explicit ?? $positional;

        if ($name !== null) {
            $profile = Profile::resolve($name);

            if ($profile === null) {
                throw new UiException(sprintf(
                    "Unknown profile [%s].\nAvailable profiles:\n%s",
                    $name,
                    $this->profileChoices(),
                ));
            }

            return $profile;
        }

        [$detected] = $this->statusInspector()->detectProfile($basePath);

        if ($detected !== null) {
            return $detected;
        }

        throw new UiException(
            "No profile was given and none is recorded in .sahil-ui.json.\nAvailable profiles:\n"
            .$this->profileChoices(),
        );
    }

    private function profileChoices(): string
    {
        $lines = [];

        foreach (Profile::cases() as $profile) {
            $lines[] = sprintf('  %-11s %s', $profile->value, $profile->description());
        }

        return implode("\n", $lines);
    }

    /**
     * @param array{positional: list<string>, path: ?string, profile: ?string, source: ?string, dry_run: bool, merge: bool, overwrite: bool, no_install: bool, json: bool, clean: bool, host: ?string, port: ?string} $options
     */
    private function rejectPositional(array $options, string $command): void
    {
        if ($options['positional'] !== []) {
            throw new UiException(sprintf(
                '%s: unexpected argument [%s].',
                $command,
                implode(' ', $options['positional']),
            ));
        }
    }

    private function readyToolchain(string $basePath, bool $allowMissing): Toolchain
    {
        $toolchain = (new ToolchainDetector())->detect($basePath);

        if (!$toolchain->isReady() && !$allowMissing) {
            throw new UiException(
                "Missing frontend prerequisites:\n  - "
                .implode("\n  - ", $toolchain->issues()),
            );
        }

        return $toolchain;
    }

    private function ensureDependencies(string $basePath, Toolchain $toolchain): void
    {
        if (is_file($basePath.'/node_modules/vite/package.json')) {
            return;
        }

        throw new UiException(sprintf(
            'Frontend dependencies are not installed (node_modules/vite is missing).%s'
            .'Run `bin/sahil ui:install` or `%s install`.',
            PHP_EOL,
            $toolchain->packageManager ?? 'npm',
        ));
    }

    /**
     * @return list<string>
     */
    private function managerCommand(Toolchain $toolchain, bool $allowFallback): array
    {
        if ($toolchain->hasPackageManager()) {
            return $toolchain->command();
        }

        if ($allowFallback) {
            return [$toolchain->packageManager ?? 'npm'];
        }

        return $toolchain->command();
    }

    private function packageScript(string $basePath, string $script): ?string
    {
        $path = $basePath.'/package.json';

        if (!is_file($path)) {
            throw new UiException('package.json is missing. Run `bin/sahil ui:install` first.');
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (!is_array($decoded) || !isset($decoded['scripts']) || !is_array($decoded['scripts'])) {
            return null;
        }

        $value = $decoded['scripts'][$script] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function removeBuildDirectory(string $basePath): void
    {
        $target = $basePath.'/public/build';

        if (!is_dir($target)) {
            return;
        }

        $resolved = realpath($target);
        $root = realpath($basePath);

        if ($resolved === false || $root === false || !str_starts_with($resolved, $root.DIRECTORY_SEPARATOR)) {
            throw new UiException(sprintf('Refusing to remove [%s]: it is outside the project.', $target));
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $item */
        foreach ($items as $item) {
            $path = $item->getPathname();

            if ($item->isDir() && !$item->isLink()) {
                if (!rmdir($path)) {
                    throw new UiException(sprintf('Unable to remove directory [%s].', $path));
                }
            } elseif (!unlink($path)) {
                throw new UiException(sprintf('Unable to remove file [%s].', $path));
            }
        }

        if (!rmdir($resolved)) {
            throw new UiException(sprintf('Unable to remove directory [%s].', $resolved));
        }
    }

    private function projectPath(string $basePath, ?string $option): string
    {
        $path = $option ?? $basePath;

        if (
            !str_starts_with($path, DIRECTORY_SEPARATOR)
            && preg_match('/^[A-Za-z]:[\\\\\/]/', $path) !== 1
        ) {
            $path = rtrim($basePath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$path;
        }

        return $path;
    }

    /**
     * @param list<string> $command
     */
    private function describe(array $command): string
    {
        return (new ProcessResult($command, 0))->describe();
    }

    private function emitProcessOutput(ProcessResult $result): void
    {
        $stdout = trim($result->stdout);
        $stderr = trim($result->stderr);

        if ($stdout !== '') {
            $this->line($stdout);
        }

        if ($stderr !== '') {
            $this->error($stderr);
        }
    }

    private function installer(): UiInstaller
    {
        return $this->installer ?? new UiInstaller();
    }

    private function statusInspector(): UiStatus
    {
        return $this->status ?? new UiStatus();
    }

    private function runner(): ProcessRunner
    {
        return $this->runner ?? new ProcessRunner();
    }

    private function themeInstaller(): ThemeInstaller
    {
        return $this->themeInstaller ?? new ThemeInstaller();
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1024 * 1024) {
            return sprintf('%.1f kB', $bytes / 1024);
        }

        return sprintf('%.1f MB', $bytes / (1024 * 1024));
    }

    private function line(string $line = ''): void
    {
        fwrite(STDOUT, $line.PHP_EOL);
    }

    private function error(string $line): void
    {
        fwrite(STDERR, $line.PHP_EOL);
    }

    /**
     * Copy every file from `$source` into `$destination`, creating
     * directories as needed. Existing files are overwritten.
     */
    private function copyStubDirectory(string $source, string $destination): void
    {
        if (!is_dir($source)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relative = $iterator->getSubPathname();

            if ($relative === '') {
                continue;
            }

            $target = $destination.DIRECTORY_SEPARATOR.$relative;

            if ($item->isDir() && !$item->isLink()) {
                if (!is_dir($target) && !mkdir($target, 0o775, true) && !is_dir($target)) {
                    throw new UiException(sprintf('Unable to create directory [%s].', $target));
                }

                continue;
            }

            $this->copyFile($item->getPathname(), $target);
        }
    }

    private function copyFile(string $source, string $destination): void
    {
        $directory = dirname($destination);

        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new UiException(sprintf('Unable to create directory [%s].', $directory));
        }

        if (!copy($source, $destination)) {
            throw new UiException(sprintf('Unable to copy [%s] to [%s].', $source, $destination));
        }
    }

    private function writeUiManifest(string $basePath, Profile $profile): void
    {
        $manifest = [
            'profile' => $profile->value,
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'installed_by' => 'bin/sahil ui:install',
        ];

        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw new UiException('Unable to encode the .sahil-ui.json manifest.');
        }

        $this->writeFileAtomic($basePath.'/.sahil-ui.json', $json."\n");
    }

    private function removeStubTree(string $stubsDir): void
    {
        if (!is_dir($stubsDir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($stubsDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $path = $item->getPathname();

            if ($item->isDir() && !$item->isLink()) {
                @rmdir($path);

                continue;
            }

            @unlink($path);
        }

        @rmdir($stubsDir);
    }

    private function writeFileAtomic(string $path, string $contents): void
    {
        $temporary = $path.'.tmp-'.bin2hex(random_bytes(4));
        $written = @file_put_contents($temporary, $contents);

        if ($written === false || $written !== strlen($contents)) {
            @unlink($temporary);

            throw new UiException(sprintf('Unable to write [%s].', $path));
        }

        if (!@rename($temporary, $path)) {
            @unlink($temporary);

            throw new UiException(sprintf('Unable to replace [%s].', $path));
        }
    }

    private function hasNode(): bool
    {
        $command = PHP_OS_FAMILY === 'windows' ? 'where' : 'command -v';

        exec(sprintf('%s node 2>/dev/null', $command), $output, $exitCode);

        return $exitCode === 0 && $output !== [];
    }

    private function isInteractive(): bool
    {
        return defined('STDIN')
            && function_exists('stream_isatty')
            && @stream_isatty(STDIN);
    }

    private function readPrompt(string $prompt): ?string
    {
        fwrite(STDOUT, $prompt);

        $line = @fgets(STDIN);

        if ($line === false) {
            return null;
        }

        return rtrim($line, "\r\n");
    }
}
