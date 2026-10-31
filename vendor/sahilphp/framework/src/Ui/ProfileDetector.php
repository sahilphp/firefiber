<?php

declare(strict_types=1);

namespace Sahil\Ui;

/**
 * Infers the UI profile a project uses.
 *
 * `.sahil-ui.json` is authoritative: it is written by `ui:install` and
 * records the profile explicitly. Projects installed before the manifest
 * existed (or configured by hand) fall back to the packages in
 * `package.json`.
 */
final class ProfileDetector
{
    /**
     * @return array{0: ?Profile, 1: ?string} [profile, source]
     */
    public function detect(string $basePath): array
    {
        $manifestPath = rtrim($basePath, '/').'/.sahil-ui.json';

        if (is_file($manifestPath)) {
            $contents = file_get_contents($manifestPath);

            if ($contents !== false) {
                $decoded = json_decode($contents, true);

                if (is_array($decoded) && isset($decoded['profile']) && is_string($decoded['profile'])) {
                    $profile = Profile::resolve($decoded['profile']);

                    if ($profile !== null) {
                        return [$profile, '.sahil-ui.json'];
                    }
                }
            }
        }

        $packagePath = rtrim($basePath, '/').'/package.json';

        if (!is_file($packagePath)) {
            return [null, null];
        }

        $contents = file_get_contents($packagePath);

        if ($contents === false) {
            return [null, null];
        }

        $decoded = json_decode($contents, true);

        if (!is_array($decoded)) {
            return [null, null];
        }

        $dependencies = [];

        foreach (['dependencies', 'devDependencies'] as $section) {
            if (isset($decoded[$section]) && is_array($decoded[$section])) {
                foreach (array_keys($decoded[$section]) as $name) {
                    $dependencies[(string) $name] = true;
                }
            }
        }

        $tailwind = isset($dependencies['tailwindcss']) || isset($dependencies['@tailwindcss/vite']);
        $bootstrap = isset($dependencies['bootstrap']);

        return match (true) {
            $tailwind && $bootstrap => [Profile::Both, 'package.json dependencies'],
            $tailwind => [Profile::Tailwind, 'package.json dependencies'],
            $bootstrap => [Profile::Bootstrap, 'package.json dependencies'],
            isset($dependencies['vite']) => [Profile::None, 'package.json dependencies'],
            default => [null, null],
        };
    }
}
