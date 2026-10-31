<?php

declare(strict_types=1);

namespace Sahil\View;

/**
 * Turns a template name into a file path — safely.
 *
 * Names use dots or slashes as directory separators
 * (`layouts.app` === `layouts/app`), and every segment must be a plain
 * `[A-Za-z0-9_-]+`. That single rule rejects the whole traversal family:
 *
 *   `../../etc/passwd`   → the `..` segments are not valid names
 *   `/etc/passwd`        → a leading separator produces an empty segment
 *   `a/../../b`          → again invalid segments
 *
 * The name check is backed by a second, independent check: the resolved
 * file is passed through `realpath()` and must live inside the root it was
 * found under, which also catches a symlink inside a view directory
 * pointing somewhere else.
 *
 * Multiple roots are searched in order and the first hit wins, so an
 * application can override a package's view by putting its own root first.
 */
final class TemplateResolver
{
    /** @var list<string> absolute view roots, in search order */
    private array $roots;

    /**
     * @param list<string> $roots absolute directories, in search order
     */
    public function __construct(array $roots, private readonly string $extension = '.sahil.php')
    {
        if ($roots === []) {
            throw new ViewException('At least one view directory must be configured.');
        }

        if ($extension === '' || !str_starts_with($extension, '.')) {
            throw new ViewException(sprintf(
                'The template extension [%s] must start with a dot (e.g. ".sahil.php").',
                $extension,
            ));
        }

        $this->roots = array_map(
            static fn (string $root): string => rtrim($root, '/'),
            $roots,
        );
    }

    /**
     * @return list<string>
     */
    public function roots(): array
    {
        return $this->roots;
    }

    public function extension(): string
    {
        return $this->extension;
    }

    /**
     * Absolute path of a template, or a {@see ViewNotFoundException} listing
     * every directory that was searched.
     */
    public function resolve(string $name): string
    {
        $relative = $this->relativePath($name);

        $searched = [];

        foreach ($this->roots as $root) {
            $searched[] = $root;
            $candidate = $root.'/'.$relative.$this->extension;

            if (!is_file($candidate)) {
                continue;
            }

            $real = realpath($candidate);

            // Defence in depth: the file must still be inside its root once
            // symlinks are resolved.
            if ($real === false || !str_starts_with($real, rtrim($root, '/').'/')) {
                continue;
            }

            return $real;
        }

        throw new ViewNotFoundException($name, $searched);
    }

    /**
     * Whether a name resolves to a readable file. A malformed name (bad
     * characters, traversal) is `false`, not an error: callers use this for
     * feature detection, while {@see resolve()} stays strict.
     */
    public function exists(string $name): bool
    {
        try {
            $this->resolve($name);

            return true;
        } catch (ViewException) {
            return false;
        }
    }

    /**
     * Validated, normalised path relative to a view root (without the
     * extension). Public so callers can validate a name without touching
     * the filesystem.
     */
    public function relativePath(string $name): string
    {
        if ($name === '') {
            throw new ViewException('A template name must not be empty.');
        }

        if (str_contains($name, "\0")) {
            throw new ViewException(sprintf('Template name [%s] contains a NUL byte.', $name));
        }

        if (str_contains($name, '\\')) {
            throw new ViewException(sprintf(
                'Template name [%s] must use dots or forward slashes, not backslashes.',
                $name,
            ));
        }

        $normalised = str_replace(['.', '/'], '/', $name);
        $segments = explode('/', $normalised);

        $valid = [];

        foreach ($segments as $segment) {
            if ($segment === '' || preg_match('/^[A-Za-z0-9_-]+$/', $segment) !== 1) {
                throw new ViewException(sprintf(
                    'Template name [%s] is not valid: every segment must match [A-Za-z0-9_-]+ and traversal is not allowed.',
                    $name,
                ));
            }

            $valid[] = $segment;
        }

        return implode('/', $valid);
    }
}
