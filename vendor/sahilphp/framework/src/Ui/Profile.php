<?php

declare(strict_types=1);

namespace Sahil\Ui;

/**
 * Supported frontend installation profiles.
 *
 * A profile is a closed set of generated files and npm packages. Commands
 * never generate files that belong to another profile, so installing
 * `bootstrap` cannot leave Tailwind build inputs behind.
 */
enum Profile: string
{
    case Tailwind = 'tailwind';
    case Bootstrap = 'bootstrap';
    case Both = 'both';
    case None = 'none';

    /**
     * Resolve a user-supplied profile name. Aliases exist for the names
     * people actually type (`tailwindcss`, `bootstrap5`, `plain`, ...).
     */
    public static function resolve(string $name): ?self
    {
        return match (strtolower(trim($name))) {
            'tailwind', 'tailwindcss', 'tailwind-css', 'tw' => self::Tailwind,
            'bootstrap', 'bootstrap5', 'bootstrap-5', 'bs' => self::Bootstrap,
            'both', 'all', 'tailwind+bootstrap', 'bootstrap+tailwind' => self::Both,
            'none', 'plain', 'vanilla', 'no-framework' => self::None,
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Tailwind => 'Tailwind CSS v4',
            self::Bootstrap => 'Bootstrap 5',
            self::Both => 'Tailwind CSS v4 + Bootstrap 5',
            self::None => 'Plain CSS (no framework)',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Tailwind => 'Tailwind v4 through the official Vite plugin.',
            self::Bootstrap => 'Bootstrap 5 CSS and JavaScript (precompiled distribution).',
            self::Both => 'Both frameworks, built by one Vite configuration into separate files.',
            self::None => 'Vite-managed plain CSS without a CSS framework.',
        };
    }

    public function includesTailwind(): bool
    {
        return $this === self::Tailwind || $this === self::Both;
    }

    public function includesBootstrap(): bool
    {
        return $this === self::Bootstrap || $this === self::Both;
    }

    /**
     * Stylesheet entry points that make this profile complete.
     *
     * `both` deliberately returns two entries: the profile was selected
     * explicitly, so both frameworks are expected. Every other profile
     * returns exactly what it needs, never a framework it did not install.
     *
     * @return list<string>
     */
    public function cssEntries(): array
    {
        return match ($this) {
            self::Tailwind => ['tailwind'],
            self::Bootstrap => ['bootstrap'],
            self::Both => ['tailwind', 'bootstrap'],
            self::None => ['app'],
        };
    }

    /**
     * JavaScript entry points that make this profile complete.
     *
     * Tailwind and plain CSS are CSS-only profiles.
     *
     * @return list<string>
     */
    public function jsEntries(): array
    {
        return match ($this) {
            self::Tailwind, self::None => [],
            self::Bootstrap, self::Both => ['bootstrap'],
        };
    }

    /**
     * npm packages required to install and build this profile.
     *
     * All are build-time dependencies; they are written to `devDependencies`
     * so a production `npm install --omit=dev` does not ship them.
     *
     * @return array<string, string>
     */
    public function dependencies(): array
    {
        $dependencies = [
            'vite' => '^8.3.5',
        ];

        if ($this->includesTailwind()) {
            $dependencies['@tailwindcss/vite'] = '^4.3.3';
            $dependencies['tailwindcss'] = '^4.3.3';
        }

        if ($this->includesBootstrap()) {
            $dependencies['bootstrap'] = '^5.3.8';
        }

        ksort($dependencies);

        return $dependencies;
    }

    /**
     * Source files this profile owns (relative to the project root).
     *
     * `package.json` and Vite's single configuration file are shared by all
     * profiles; the source entries differ per profile.
     *
     * @return list<string>
     */
    public function sourceFiles(): array
    {
        $files = ['package.json', 'vite.config.js'];

        if ($this === self::Tailwind || $this === self::Both) {
            $files[] = 'resources/css/tailwind.css';
        }

        if ($this === self::Bootstrap || $this === self::Both) {
            $files[] = 'resources/css/bootstrap.css';
            $files[] = 'resources/js/bootstrap.js';
        }

        if ($this === self::None) {
            $files[] = 'resources/css/app.css';
        }

        return $files;
    }

    /**
     * Build outputs expected from `ui:build` (relative to project root).
     *
     * Filenames are deterministic (no content hashes) because SahilPHP
     * templates reference stable asset paths.
     *
     * @return list<string>
     */
    public function expectedAssets(): array
    {
        $assets = [];

        if ($this === self::Tailwind || $this === self::Both) {
            $assets[] = 'public/build/assets/tailwind.css';
        }

        if ($this === self::Bootstrap || $this === self::Both) {
            $assets[] = 'public/build/assets/bootstrap.css';
            $assets[] = 'public/build/assets/bootstrap.js';
        }

        if ($this === self::None) {
            $assets[] = 'public/build/assets/app.css';
        }

        return $assets;
    }

    /**
     * Build-time packages that must exist inside `node_modules`.
     *
     * @return list<string>
     */
    public function requiredPackages(): array
    {
        return array_keys($this->dependencies());
    }

    /**
     * @return array<string, string>
     */
    public static function choices(): array
    {
        $choices = [];

        foreach (self::cases() as $profile) {
            $choices[$profile->value] = $profile->label();
        }

        return $choices;
    }
}
