<?php

declare(strict_types=1);

namespace Sahil\Theme;

use Sahil\Ui\Profile;

/**
 * Applies a theme plan to a project directory.
 *
 * The neutral token document and the override stylesheets are user-owned:
 * they are created once and preserved. Generated files carry a header that
 * says they are managed by `ui:theme`, so replacing them is safe. Entry
 * stylesheets are edited only inside a replaceable `@sahil-theme` marker
 * block, leaving application CSS around it untouched.
 */
final class ThemeInstaller
{
    private const THEME_START = '/* @sahil-theme:start */';
    private const THEME_END = '/* @sahil-theme:end */';

    public function __construct(
        private readonly ThemeGenerator $generator = new ThemeGenerator(),
    ) {
    }

    public function install(
        string $basePath,
        Profile $profile,
        ?string $source = null,
        bool $dryRun = false,
    ): ThemeReport {
        $basePath = $this->projectDirectory($basePath);
        $sourceName = $source ?? 'theme/tokens.json';
        $sourcePath = $this->projectPath($basePath, $sourceName);
        $sourceExists = is_file($sourcePath);
        $createSource = false;

        if (!$sourceExists) {
            if ($source !== null) {
                throw new ThemeException(sprintf('Theme source [%s] was not found.', $sourcePath));
            }

            $tokens = ThemeTokens::sample();
            $createSource = true;
        } else {
            $tokens = ThemeTokens::fromFile($sourcePath);
        }

        $plan = $this->generator->plan($tokens, $profile);

        // Validate every entry point before writing anything: a theme cannot be
        // installed into a project whose framework entry files are missing.
        foreach (array_keys($plan->entries) as $entry) {
            if (!is_file($basePath.'/'.$entry)) {
                throw new ThemeException(sprintf(
                    'Cannot add theme imports to [%s] because the file does not exist. '
                    .'Run `bin/sahil ui:install %s` first.',
                    $entry,
                    $profile->value,
                ));
            }
        }

        $report = new ThemeReport(
            profile: $profile,
            theme: $tokens->name,
            source: $sourceName,
            dryRun: $dryRun,
            sourceCreated: $createSource,
        );

        $actions = [];

        if ($createSource) {
            $actions[$sourceName] = [
                'path' => $sourcePath,
                'content' => $tokens->toJson(),
                'kind' => 'create',
            ];
        }

        foreach ($plan->generated as $relative => $content) {
            $path = $basePath.'/'.$relative;
            $current = is_file($path) ? $this->readFile($path) : null;

            $actions[$relative] = [
                'path' => $path,
                'content' => $content,
                'kind' => $current === null ? 'create' : ($current === $content ? 'skip' : 'update'),
            ];
        }

        foreach ($plan->user as $relative => $content) {
            $path = $basePath.'/'.$relative;
            $current = is_file($path) ? $this->readFile($path) : null;

            $actions[$relative] = [
                'path' => $path,
                'content' => $current ?? $content,
                'kind' => $current === null ? 'create' : 'preserved',
            ];
        }

        foreach ($plan->entries as $relative => $block) {
            $path = $basePath.'/'.$relative;
            $current = $this->readFile($path);
            $patched = $this->patchEntry($current, $block);

            $actions[$relative] = [
                'path' => $path,
                'content' => $patched,
                'kind' => $current === $patched ? 'skip' : 'update',
            ];
        }

        if ($dryRun) {
            $this->record($report, $actions);
            $this->sortReport($report);

            return $report;
        }

        foreach ($actions as $relative => $action) {
            if ($action['kind'] === 'create') {
                $this->writeFile($action['path'], $action['content']);
                $report->created[] = $relative;

                continue;
            }

            if ($action['kind'] === 'update') {
                $this->writeFile($action['path'], $action['content']);
                $report->updated[] = $relative;

                continue;
            }

            if ($action['kind'] === 'preserved') {
                $report->preserved[] = $relative;

                continue;
            }

            $report->skipped[] = $relative;
        }

        $this->sortReport($report);

        return $report;
    }

    private function sortReport(ThemeReport $report): void
    {
        sort($report->created);
        sort($report->updated);
        sort($report->preserved);
        sort($report->skipped);
    }

    /**
     * @param array<string, array{path: string, content: string, kind: string}> $actions
     */
    private function record(ThemeReport $report, array $actions): void
    {
        foreach ($actions as $relative => $action) {
            match ($action['kind']) {
                'create' => $report->created[] = $relative,
                'update' => $report->updated[] = $relative,
                'preserved' => $report->preserved[] = $relative,
                default => $report->skipped[] = $relative,
            };
        }
    }

    private function patchEntry(string $css, string $block): string
    {
        $pattern = '/'.preg_quote(self::THEME_START, '/').'.*?'.preg_quote(self::THEME_END, '/').'/s';

        if (preg_match($pattern, $css) === 1) {
            $patched = preg_replace($pattern, $block, $css, 1);

            if (!is_string($patched)) {
                throw new ThemeException('Unable to replace the existing theme marker block.');
            }

            return $patched;
        }

        if (preg_match('/^@import[^;\r\n]*;[ \t]*$/m', $css, $match, PREG_OFFSET_CAPTURE) === 1) {
            $offset = $match[0][1] + strlen($match[0][0]);

            return substr($css, 0, $offset)."\n\n".$block."\n".substr($css, $offset);
        }

        return $block."\n\n".$css;
    }

    private function projectDirectory(string $basePath): string
    {
        if (!is_dir($basePath)) {
            throw new ThemeException(sprintf('Project directory [%s] does not exist.', $basePath));
        }

        $resolved = realpath($basePath);

        if ($resolved === false) {
            throw new ThemeException(sprintf('Unable to resolve project directory [%s].', $basePath));
        }

        return rtrim($resolved, DIRECTORY_SEPARATOR);
    }

    private function projectPath(string $basePath, string $path): string
    {
        if (
            str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1
        ) {
            return $path;
        }

        return $basePath.DIRECTORY_SEPARATOR.$path;
    }

    private function readFile(string $path): string
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new ThemeException(sprintf('Unable to read [%s].', $path));
        }

        return $contents;
    }

    private function writeFile(string $path, string $contents): void
    {
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new ThemeException(sprintf('Unable to create directory [%s].', $directory));
        }

        $temporary = $path.'.tmp-'.bin2hex(random_bytes(4));
        $written = file_put_contents($temporary, $contents);

        if ($written === false || $written !== strlen($contents)) {
            @unlink($temporary);

            throw new ThemeException(sprintf('Unable to write [%s].', $path));
        }

        if (!rename($temporary, $path)) {
            @unlink($temporary);

            throw new ThemeException(sprintf('Unable to replace [%s].', $path));
        }
    }
}
