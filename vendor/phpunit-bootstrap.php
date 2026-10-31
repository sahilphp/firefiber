<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap for the Firefiber test suite.
 *
 * PHPUnit 10.5.40 does not ship a PSR-4 autoloader for the extracted source
 * tree (its composer.json uses a classmap autoload for src/). Rather than
 * guessing at PHPUnit's namespace-to-directory layout, this file builds a
 * classmap from the extracted source tree at bootstrap time and registers an
 * autoloader for the PHPUnit\Framework and PHPUnit\TextUI namespaces so that
 * test files can extend PHPUnit\Framework\TestCase without a full
 * composer-installed phpunit/phpunit package.
 */

/** Build a full class name (with namespace) -> file path map. */
function pf_build_classmap(string $dir): array
{
    $map = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($it as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
            $full = $file->getPathname();
            $source = file_get_contents($full);

            // Extract the namespace declared at the top of the file.
            if (preg_match('/namespace\s+([^;]+);/', $source, $m)) {
                $namespace = rtrim($m[1], '\\') . '\\';
            } else {
                $namespace = '';
            }

            // Extract the first class/interface/trait name.
            $type = '';
            $name = '';
            if (preg_match('/class\s+([A-Za-z_][A-Za-z0-9_]*)\s*(?:extends|implements|\s*\{)/i', $source, $m)) {
                $type = 'class';
                $name = $m[1];
            } elseif (preg_match('/\binterface\s+([A-Za-z_][A-Za-z0-9_]*)\b/i', $source, $m)) {
                $type = 'interface';
                $name = $m[1];
            } elseif (preg_match('/\btrait\s+([A-Za-z_][A-Za-z0-9_]*)\b/i', $source, $m)) {
                $type = 'trait';
                $name = $m[1];
            }

            if ($name !== '') {
                $fullClass = $namespace . $name;
                $map[$fullClass] = $full;
            }
        }
    }

    return $map;
}

$phpunitSrc = __DIR__ . '/phpunit/phpunit/src';
$phpunitClassMap = pf_build_classmap($phpunitSrc);

spl_autoload_register(static function (string $class) use ($phpunitClassMap): void {
    if (str_starts_with($class, 'PHPUnit\\')) {
        if (isset($phpunitClassMap[$class])) {
            require $phpunitClassMap[$class];
        }
    }
}, true, true);

// Replay the classmap lookup for any class whose file was just required,
// so that interface/trait/class dependencies resolve transitively.
spl_autoload_register(static function (string $depClass) use ($phpunitClassMap): void {
    if (str_starts_with($depClass, 'PHPUnit\\') && isset($phpunitClassMap[$depClass])) {
        $depFile = $phpunitClassMap[$depClass];
        if (is_file($depFile)) {
            require $depFile;
        }
    }
}, true, true);
