<?php

declare(strict_types=1);

namespace Sahil\Firefiber\Assets;

use Stringable;

/**
 * Renders safe HTML tags for package-managed assets.
 *
 * Callers may add metadata attributes (`nonce`, `media`, `id`, …), but the
 * attributes that determine what is loaded (`src`, `href`, `rel`,
 * `integrity`) and the runtime's `defer` flag cannot be overridden. Event
 * handler attributes are ignored, and all values are HTML-escaped.
 */
final class AssetTagRenderer
{
    /**
     * @param array<array-key, bool|int|float|string|Stringable|null> $attributes
     */
    public static function script(Asset $asset, array $attributes = []): string
    {
        return self::render(
            [
                'src' => $asset->publicUrl(),
                'integrity' => $asset->integrity(),
                'crossorigin' => 'anonymous',
                'defer' => true,
            ],
            $attributes,
            ['src', 'integrity', 'defer'],
            'script',
        );
    }

    /**
     * @param array<array-key, bool|int|float|string|Stringable|null> $attributes
     */
    public static function stylesheet(Asset $asset, array $attributes = []): string
    {
        return self::render(
            [
                'rel' => 'stylesheet',
                'href' => $asset->publicUrl(),
                'integrity' => $asset->integrity(),
                'crossorigin' => 'anonymous',
            ],
            $attributes,
            ['rel', 'href', 'integrity'],
            'link',
        );
    }

    /**
     * @param array<string, bool|int|float|string|Stringable|null> $defaults
     * @param array<array-key, bool|int|float|string|Stringable|null> $extra
     * @param list<string> $reserved
     */
    private static function render(
        array $defaults,
        array $extra,
        array $reserved,
        string $tag,
    ): string {
        $attributes = $defaults;

        foreach ($extra as $name => $value) {
            if (!is_string($name) || preg_match('/\A[A-Za-z_:][A-Za-z0-9:_.-]*\z/', $name) !== 1) {
                continue;
            }

            $lower = strtolower($name);

            if (in_array($lower, $reserved, true) || str_starts_with($lower, 'on')) {
                continue;
            }

            if (!is_scalar($value) && !$value instanceof Stringable && $value !== null) {
                continue;
            }

            $attributes[$name] = $value;
        }

        $rendered = [];

        foreach ($attributes as $name => $value) {
            if ($value === null || $value === false) {
                continue;
            }

            if ($value === true) {
                $rendered[] = $name;

                continue;
            }

            $rendered[] = $name.'="'.htmlspecialchars(
                (string) $value,
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8',
            ).'"';
        }

        return '<'.$tag.' '.implode(' ', $rendered).'>'.($tag === 'script' ? '</script>' : '');
    }
}
