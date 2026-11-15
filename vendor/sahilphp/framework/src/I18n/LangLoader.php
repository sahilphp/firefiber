<?php

declare(strict_types=1);

namespace Sahil\I18n;

use Throwable;

/**
 * Loads `lang/{locale}/{group}.php` translation files.
 *
 * Every path registered with the loader is searched in order; the first
 * path wins per key, so an application's `lang/` directory overrides the
 * framework's bundled catalog key-by-key. Files must `return` an array
 * (nested is fine); a missing, unreadable or malformed file simply
 * contributes nothing — loading translations can never break a request.
 */
final class LangLoader
{
    /** @var list<string> search paths, most specific first */
    private array $paths = [];

    /** @var array<string, array<string, mixed>> "path\0locale\0group" => lines */
    private array $cache = [];

    /**
     * @param list<string> $paths search paths, most specific first
     */
    public function __construct(array $paths = [])
    {
        foreach ($paths as $path) {
            $this->addPath($path);
        }
    }

    /**
     * Add a search path (later paths act as fallback catalogs).
     */
    public function addPath(string $path): void
    {
        $path = rtrim($path, '/\\');

        if ($path !== '' && !in_array($path, $this->paths, true)) {
            $this->paths[] = $path;
        }
    }

    /**
     * The translation tree for one group in one locale, merged across all
     * search paths (most specific path wins per key).
     *
     * @return array<string, mixed>
     */
    public function lines(string $locale, string $group): array
    {
        $merged = [];

        foreach (array_reverse($this->paths) as $path) {
            $merged = array_replace_recursive($merged, $this->read($path, $locale, $group));
        }

        return $merged;
    }

    /**
     * Whether any search path can serve the group for the locale.
     */
    public function has(string $locale, string $group): bool
    {
        return $this->lines($locale, $group) !== [];
    }

    public function flush(): void
    {
        $this->cache = [];
    }

    /**
     * @return array<string, mixed>
     */
    private function read(string $path, string $locale, string $group): array
    {
        $cacheKey = $path."\x00".$locale."\x00".$group;

        if (array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
        }

        $file = $path.'/'.$locale.'/'.$group.'.php';
        $lines = [];

        if (is_file($file)) {
            try {
                /** @var mixed $contents */
                $contents = require $file;

                if (is_array($contents)) {
                    $lines = $contents;
                }
            } catch (Throwable) {
                // A malformed translation file is a missing catalog, not a 500.
            }
        }

        return $this->cache[$cacheKey] = $lines;
    }
}
