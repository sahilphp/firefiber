<?php

declare(strict_types=1);

namespace Sahil\Theme;

use JsonException;

/**
 * A framework-neutral theme document.
 *
 * Tokens are deliberately plain CSS values. They describe a theme without
 * depending on Tailwind, Bootstrap, Sass, or any runtime library. Generators
 * translate the same document into framework-specific output.
 *
 * @phpstan-type ColorDefinition array{light: string, dark: string}
 * @phpstan-type TypographyDefinition array{
 *     font-sans: ?string,
 *     font-mono: ?string,
 *     sizes: array<string, string>,
 *     line-heights: array<string, string>,
 *     weights: array<string, string>
 * }
 * @phpstan-type BordersDefinition array{style: string, widths: array<string, string>}
 */
final class ThemeTokens
{
    private const VERSION = 1;

    /** @var list<string> */
    private const ROOT_KEYS = [
        '$schema',
        'version',
        'name',
        'description',
        'colors',
        'typography',
        'spacing',
        'borders',
        'radii',
        'breakpoints',
    ];

    /** @var list<string> */
    private const TYPOGRAPHY_KEYS = [
        'font-sans',
        'font-mono',
        'sizes',
        'line-heights',
        'weights',
    ];

    /**
     * @param array<string, ColorDefinition> $colors
     * @param TypographyDefinition           $typography
     * @param array<string, string>          $spacing
     * @param BordersDefinition              $borders
     * @param array<string, string>          $radii
     * @param array<string, string>          $breakpoints
     */
    private function __construct(
        public readonly string $name,
        public readonly ?string $description,
        public readonly array $colors,
        public readonly array $typography,
        public readonly array $spacing,
        public readonly array $borders,
        public readonly array $radii,
        public readonly array $breakpoints,
    ) {
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new ThemeException(sprintf('Theme file [%s] is not readable.', $path));
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new ThemeException(sprintf('Unable to read theme file [%s].', $path));
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $invalid) {
            throw new ThemeException(sprintf(
                'Theme file [%s] is not valid JSON: %s',
                $path,
                $invalid->getMessage(),
            ), 0, $invalid);
        }

        return self::fromArray($decoded, $path);
    }

    /**
     * @param mixed $data decoded JSON payload
     */
    public static function fromArray(mixed $data, string $source = '<memory>'): self
    {
        if (!is_array($data) || array_is_list($data)) {
            throw new ThemeException(sprintf('Theme %s must be a JSON object.', $source));
        }

        self::rejectUnknownKeys($data, self::ROOT_KEYS, '', $source);

        $version = $data['version'] ?? self::VERSION;

        if ($version !== self::VERSION) {
            throw new ThemeException(sprintf(
                'Theme %s has unsupported version %s; expected %d.',
                $source,
                self::display($version),
                self::VERSION,
            ));
        }

        $name = $data['name'] ?? 'sahil-theme';

        if (!is_string($name) || trim($name) === '') {
            throw new ThemeException(sprintf('Theme %s: name must be a non-empty string.', $source));
        }

        $name = self::assertCommentText(trim($name), 'name', $source);
        $description = null;

        if (array_key_exists('description', $data)) {
            if (!is_string($data['description'])) {
                throw new ThemeException(sprintf('Theme %s: description must be a string.', $source));
            }

            $description = self::assertCommentText(trim($data['description']), 'description', $source);

            if ($description === '') {
                $description = null;
            }
        }

        $colors = self::colors($data['colors'] ?? null, $source);
        $typography = self::typography($data['typography'] ?? null, $source);
        $spacing = self::lengthMap($data['spacing'] ?? null, 'spacing', $source);
        $borders = self::borders($data['borders'] ?? null, $source);
        $radii = self::lengthMap($data['radii'] ?? null, 'radii', $source);
        $breakpoints = self::lengthMap($data['breakpoints'] ?? null, 'breakpoints', $source);

        return new self(
            name: $name,
            description: $description,
            colors: $colors,
            typography: $typography,
            spacing: $spacing,
            borders: $borders,
            radii: $radii,
            breakpoints: $breakpoints,
        );
    }

    public static function sample(): self
    {
        return self::fromArray([
            '$schema' => 'https://sahilphp.dev/schemas/theme-tokens-v1.json',
            'version' => self::VERSION,
            'name' => 'sahil-neutral',
            'description' => 'Neutral light and dark sample theme generated by SahilPHP.',
            'colors' => [
                'primary' => ['light' => '#2563eb', 'dark' => '#60a5fa'],
                'primary-hover' => ['light' => '#1d4ed8', 'dark' => '#93c5fd'],
                'primary-foreground' => ['light' => '#ffffff', 'dark' => '#0b1120'],
                'surface' => ['light' => '#ffffff', 'dark' => '#0f172a'],
                'surface-muted' => ['light' => '#f8fafc', 'dark' => '#1e293b'],
                'text' => ['light' => '#0f172a', 'dark' => '#e2e8f0'],
                'text-muted' => ['light' => '#475569', 'dark' => '#94a3b8'],
                'border' => ['light' => '#cbd5e1', 'dark' => '#334155'],
                'success' => ['light' => '#166534', 'dark' => '#4ade80'],
                'success-surface' => ['light' => '#dcfce7', 'dark' => '#052e16'],
                'warning' => ['light' => '#92400e', 'dark' => '#fbbf24'],
                'warning-surface' => ['light' => '#fef3c7', 'dark' => '#451a03'],
                'danger' => ['light' => '#991b1b', 'dark' => '#f87171'],
                'danger-surface' => ['light' => '#fee2e2', 'dark' => '#450a0a'],
            ],
            'typography' => [
                'font-sans' => 'ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif',
                'font-mono' => 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace',
                'sizes' => [
                    'sm' => '0.875rem',
                    'base' => '1rem',
                    'lg' => '1.125rem',
                    'xl' => '1.25rem',
                    '2xl' => '1.5rem',
                ],
                'line-heights' => [
                    'tight' => '1.25',
                    'normal' => '1.5',
                    'relaxed' => '1.625',
                ],
                'weights' => [
                    'normal' => '400',
                    'medium' => '500',
                    'semibold' => '600',
                    'bold' => '700',
                ],
            ],
            'spacing' => [
                'base' => '0.25rem',
                'xs' => '0.5rem',
                'sm' => '0.75rem',
                'md' => '1rem',
                'lg' => '1.5rem',
                'xl' => '2rem',
            ],
            'borders' => [
                'style' => 'solid',
                'widths' => [
                    'thin' => '1px',
                    'thick' => '2px',
                ],
            ],
            'radii' => [
                'sm' => '0.375rem',
                'md' => '0.5rem',
                'lg' => '0.75rem',
                'full' => '9999px',
            ],
            'breakpoints' => [
                'sm' => '40rem',
                'md' => '48rem',
                'lg' => '64rem',
                'xl' => '80rem',
            ],
        ], 'sample theme');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            '$schema' => 'https://sahilphp.dev/schemas/theme-tokens-v1.json',
            'version' => self::VERSION,
            'name' => $this->name,
        ];

        if ($this->description !== null) {
            $data['description'] = $this->description;
        }

        $data['colors'] = $this->colors;
        $data['typography'] = [];

        if ($this->typography['font-sans'] !== null) {
            $data['typography']['font-sans'] = $this->typography['font-sans'];
        }

        if ($this->typography['font-mono'] !== null) {
            $data['typography']['font-mono'] = $this->typography['font-mono'];
        }

        $data['typography']['sizes'] = $this->typography['sizes'];
        $data['typography']['line-heights'] = $this->typography['line-heights'];
        $data['typography']['weights'] = $this->typography['weights'];
        $data['spacing'] = $this->spacing;
        $data['borders'] = $this->borders;
        $data['radii'] = $this->radii;
        $data['breakpoints'] = $this->breakpoints;

        return $data;
    }

    public function toJson(): string
    {
        $json = json_encode(
            $this->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        if ($json === false) {
            throw new ThemeException('Unable to encode the theme document as JSON.');
        }

        return $json."\n";
    }

    /**
     * @return array<string, ColorDefinition>
     */
    private static function colors(mixed $value, string $source): array
    {
        if (!is_array($value) || $value === [] || array_is_list($value)) {
            throw new ThemeException(sprintf('Theme %s: colors must be a non-empty object.', $source));
        }

        $colors = [];

        foreach ($value as $name => $definition) {
            if (!is_string($name)) {
                throw new ThemeException(sprintf('Theme %s: color names must be strings.', $source));
            }

            $path = 'colors.'.$name;
            self::assertTokenName($name, $path, $source);

            if (is_string($definition)) {
                $light = $definition;
                $dark = $definition;
            } elseif (is_array($definition) && !array_is_list($definition)) {
                self::rejectUnknownKeys($definition, ['light', 'dark'], $path, $source);

                if (!isset($definition['light']) || !is_string($definition['light'])) {
                    throw new ThemeException(sprintf(
                        'Theme %s: %s.light must be a string.',
                        $source,
                        $path,
                    ));
                }

                $light = $definition['light'];

                if (array_key_exists('dark', $definition)) {
                    if (!is_string($definition['dark'])) {
                        throw new ThemeException(sprintf(
                            'Theme %s: %s.dark must be a string.',
                            $source,
                            $path,
                        ));
                    }

                    $dark = $definition['dark'];
                } else {
                    $dark = $light;
                }
            } else {
                throw new ThemeException(sprintf(
                    'Theme %s: %s must be a CSS color string or an object with light/dark values.',
                    $source,
                    $path,
                ));
            }

            $colors[$name] = [
                'light' => self::assertColor($light, $path.'.light', $source),
                'dark' => self::assertColor($dark, $path.'.dark', $source),
            ];
        }

        return $colors;
    }

    /**
     * @return TypographyDefinition
     */
    private static function typography(mixed $value, string $source): array
    {
        $typography = [
            'font-sans' => null,
            'font-mono' => null,
            'sizes' => [],
            'line-heights' => [],
            'weights' => [],
        ];

        if ($value === null) {
            return $typography;
        }

        if (!is_array($value) || array_is_list($value)) {
            throw new ThemeException(sprintf('Theme %s: typography must be an object.', $source));
        }

        self::rejectUnknownKeys($value, self::TYPOGRAPHY_KEYS, 'typography', $source);

        foreach (['font-sans', 'font-mono'] as $key) {
            if (!array_key_exists($key, $value)) {
                continue;
            }

            if (!is_string($value[$key])) {
                throw new ThemeException(sprintf(
                    'Theme %s: typography.%s must be a string.',
                    $source,
                    $key,
                ));
            }

            $typography[$key] = self::assertCssValue($value[$key], 'typography.'.$key, $source);
        }

        $typography['sizes'] = self::lengthMap(
            $value['sizes'] ?? null,
            'typography.sizes',
            $source,
        );
        $typography['line-heights'] = self::lineHeightMap(
            $value['line-heights'] ?? null,
            'typography.line-heights',
            $source,
        );
        $typography['weights'] = self::weightMap(
            $value['weights'] ?? null,
            'typography.weights',
            $source,
        );

        return $typography;
    }

    /**
     * @return BordersDefinition
     */
    private static function borders(mixed $value, string $source): array
    {
        $borders = [
            'style' => 'solid',
            'widths' => [],
        ];

        if ($value === null) {
            return $borders;
        }

        if (!is_array($value) || array_is_list($value)) {
            throw new ThemeException(sprintf('Theme %s: borders must be an object.', $source));
        }

        self::rejectUnknownKeys($value, ['style', 'widths'], 'borders', $source);

        if (array_key_exists('style', $value)) {
            if (!is_string($value['style'])) {
                throw new ThemeException(sprintf('Theme %s: borders.style must be a string.', $source));
            }

            $style = strtolower(trim($value['style']));

            if (!in_array($style, ['solid', 'dashed', 'dotted', 'double', 'none'], true)) {
                throw new ThemeException(sprintf(
                    'Theme %s: borders.style must be one of solid, dashed, dotted, double, none.',
                    $source,
                ));
            }

            $borders['style'] = $style;
        }

        $borders['widths'] = self::lengthMap($value['widths'] ?? null, 'borders.widths', $source);

        return $borders;
    }

    /**
     * @return array<string, string>
     */
    private static function lengthMap(mixed $value, string $path, string $source): array
    {
        if ($value === null) {
            return [];
        }

        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new ThemeException(sprintf('Theme %s: %s must be an object.', $source, $path));
        }

        $map = [];

        foreach ($value as $name => $token) {
            if (!is_string($name)) {
                throw new ThemeException(sprintf('Theme %s: %s keys must be strings.', $source, $path));
            }

            self::assertTokenName($name, $path.'.'.$name, $source);

            if (!is_string($token)) {
                throw new ThemeException(sprintf(
                    'Theme %s: %s.%s must be a CSS length string.',
                    $source,
                    $path,
                    $name,
                ));
            }

            $map[$name] = self::assertLength($token, $path.'.'.$name, $source);
        }

        return $map;
    }

    /**
     * @return array<string, string>
     */
    private static function lineHeightMap(mixed $value, string $path, string $source): array
    {
        if ($value === null) {
            return [];
        }

        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new ThemeException(sprintf('Theme %s: %s must be an object.', $source, $path));
        }

        $map = [];

        foreach ($value as $name => $token) {
            if (!is_string($name)) {
                throw new ThemeException(sprintf('Theme %s: %s keys must be strings.', $source, $path));
            }

            self::assertTokenName($name, $path.'.'.$name, $source);

            if (!is_string($token)) {
                throw new ThemeException(sprintf(
                    'Theme %s: %s.%s must be a number or CSS length string.',
                    $source,
                    $path,
                    $name,
                ));
            }

            $map[$name] = self::assertLineHeight($token, $path.'.'.$name, $source);
        }

        return $map;
    }

    /**
     * @return array<string, string>
     */
    private static function weightMap(mixed $value, string $path, string $source): array
    {
        if ($value === null) {
            return [];
        }

        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new ThemeException(sprintf('Theme %s: %s must be an object.', $source, $path));
        }

        $map = [];

        foreach ($value as $name => $token) {
            if (!is_string($name)) {
                throw new ThemeException(sprintf('Theme %s: %s keys must be strings.', $source, $path));
            }

            self::assertTokenName($name, $path.'.'.$name, $source);

            if (is_int($token)) {
                $token = (string) $token;
            }

            if (!is_string($token)) {
                throw new ThemeException(sprintf(
                    'Theme %s: %s.%s must be a font-weight number or keyword.',
                    $source,
                    $path,
                    $name,
                ));
            }

            $weight = trim($token);

            if (
                !in_array($weight, ['normal', 'bold', 'bolder', 'lighter'], true)
                && preg_match('/^[1-9]00$/', $weight) !== 1
            ) {
                throw new ThemeException(sprintf(
                    'Theme %s: %s.%s is not a valid font weight.',
                    $source,
                    $path,
                    $name,
                ));
            }

            $map[$name] = $weight;
        }

        return $map;
    }

    /**
     * @param array<mixed> $value
     * @param list<string> $allowed
     */
    private static function rejectUnknownKeys(array $value, array $allowed, string $path, string $source): void
    {
        foreach (array_keys($value) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new ThemeException(sprintf(
                    'Theme %s: unknown key [%s]%s.',
                    $source,
                    self::display($key),
                    $path === '' ? '' : ' in '.$path,
                ));
            }
        }
    }

    private static function assertTokenName(string $name, string $path, string $source): void
    {
        if (preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $name) !== 1) {
            throw new ThemeException(sprintf(
                'Theme %s: %s must use lowercase letters, digits and hyphens (for example "primary" or "2xl").',
                $source,
                $path,
            ));
        }
    }

    private static function assertColor(string $value, string $path, string $source): string
    {
        $value = self::assertCssValue($value, $path, $source);

        $function = '/^(?:rgb|rgba|hsl|hsla|hwb|lab|lch|oklab|oklch|color|color-mix|light-dark)\(.+\)$/i';
        $hex = '/^#(?:[0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/i';

        if (
            preg_match($hex, $value) !== 1
            && preg_match($function, $value) !== 1
            && preg_match('/^[a-zA-Z]+$/', $value) !== 1
        ) {
            throw new ThemeException(sprintf(
                'Theme %s: %s is not a supported CSS color value.',
                $source,
                $path,
            ));
        }

        return $value;
    }

    private static function assertLength(string $value, string $path, string $source): string
    {
        $value = self::assertCssValue($value, $path, $source);

        if (!self::isLength($value)) {
            throw new ThemeException(sprintf(
                'Theme %s: %s must be a CSS length (for example 1rem, 16px, 0, clamp(...)).',
                $source,
                $path,
            ));
        }

        return $value;
    }

    private static function assertLineHeight(string $value, string $path, string $source): string
    {
        $value = self::assertCssValue($value, $path, $source);

        if ($value === 'normal' || self::isNumber($value) || self::isLength($value)) {
            return $value;
        }

        throw new ThemeException(sprintf(
            'Theme %s: %s must be normal, a unitless number, or a CSS length.',
            $source,
            $path,
        ));
    }

    private static function assertCssValue(string $value, string $path, string $source): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new ThemeException(sprintf('Theme %s: %s must not be empty.', $source, $path));
        }

        if (preg_match('/[;{}\r\n\0<>$@!]/', $value) === 1) {
            throw new ThemeException(sprintf(
                'Theme %s: %s contains characters that are not allowed in a CSS token.',
                $source,
                $path,
            ));
        }

        if (
            str_contains($value, '/*')
            || str_contains($value, '*/')
            || stripos($value, 'url(') !== false
            || stripos($value, 'expression(') !== false
        ) {
            throw new ThemeException(sprintf(
                'Theme %s: %s contains an unsafe CSS construct.',
                $source,
                $path,
            ));
        }

        return $value;
    }

    private static function assertCommentText(string $value, string $path, string $source): string
    {
        if (preg_match('/[;\r\n\0<>]/', $value) === 1 || str_contains($value, '/*') || str_contains($value, '*/')) {
            throw new ThemeException(sprintf(
                'Theme %s: %s contains characters that cannot be used in generated comments.',
                $source,
                $path,
            ));
        }

        return $value;
    }

    private static function isLength(string $value): bool
    {
        if ($value === '0') {
            return true;
        }

        if (preg_match('/^[-+]?(?:\d*\.)?\d+(?:px|rem|em|%|vh|vw|vmin|vmax|ch|ex|pt|pc|in|cm|mm|q)$/i', $value) === 1) {
            return true;
        }

        return preg_match('/^(?:calc|clamp|min|max)\(/i', $value) === 1;
    }

    private static function isNumber(string $value): bool
    {
        return preg_match('/^[-+]?(?:\d*\.)?\d+$/', $value) === 1;
    }

    private static function display(mixed $value): string
    {
        if (is_scalar($value)) {
            return (string) $value;
        }

        return get_debug_type($value);
    }
}
