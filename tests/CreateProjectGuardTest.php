<?php

declare(strict_types=1);

namespace Sahil\Firefiber\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The package is a library. `composer create-project` must fail clearly, and
 * that behaviour is what the post-create-project-cmd script provides.
 */
final class CreateProjectGuardTest extends TestCase
{
    public function test_composer_json_is_a_library_with_the_guard_script(): void
    {
        $root = dirname(__DIR__);
        $composer = json_decode((string) file_get_contents($root . '/composer.json'), true);

        self::assertIsArray($composer);
        self::assertSame('library', $composer['type'] ?? null);
        self::assertSame(
            ['@php scripts/refuse-create-project.php'],
            $composer['scripts']['post-create-project-cmd'] ?? null,
        );
    }

    public function test_create_project_script_exits_one_with_a_clear_error(): void
    {
        $root = dirname(__DIR__);
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, $root . '/scripts/refuse-create-project.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
        );

        self::assertIsResource($process);
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);

        self::assertSame(1, proc_close($process));
        self::assertStringContainsString('This package cannot be used as a project skeleton.', $stderr);
        self::assertStringContainsString('sahilphp new my-app', $stderr);
    }
}
