<?php

declare(strict_types=1);

namespace Sahil\Testing;

use RuntimeException;

/**
 * Testing-infrastructure failure: no HTTP kernel bound, no CLI binary, a
 * malformed override, an unusable temporary directory.
 *
 * Distinct from an assertion failure: this means the test harness could not be
 * set up as requested, and the message says what to fix.
 */
final class TestingException extends RuntimeException
{
}
