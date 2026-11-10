<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Sahil\Firefiber\Exceptions\CsrfException;
use Sahil\Firefiber\Exceptions\InvalidSnapshotException;
use Sahil\Firefiber\Exceptions\InvalidStateException;
use Sahil\Firefiber\Exceptions\UnknownActionException;

/**
 * Test case for Firefiber exceptions.
 */
final class ExceptionsTest extends TestCase
{
    public function test_csrf_exception_has_message(): void
    {
        $exception = new CsrfException('CSRF token mismatch');

        $this->assertSame('CSRF token mismatch', $exception->getMessage());
    }

    public function test_invalid_state_exception_has_message(): void
    {
        $exception = new InvalidStateException('State is corrupt');

        $this->assertSame('State is corrupt', $exception->getMessage());
    }

    public function test_invalid_snapshot_exception_has_message(): void
    {
        $exception = new InvalidSnapshotException('Snapshot signature invalid');

        $this->assertSame('Snapshot signature invalid', $exception->getMessage());
    }

    public function test_unknown_action_exception_has_message(): void
    {
        $exception = new UnknownActionException('invalid_action', 'ff-abc123');

        $this->assertStringContainsString('invalid_action', $exception->getMessage());
        $this->assertStringContainsString('ff-abc123', $exception->getMessage());
    }

    public function test_exceptions_extend_runtime_exception(): void
    {
        $exceptions = [
            new CsrfException(),
            new InvalidStateException(),
            new InvalidSnapshotException(),
            new UnknownActionException('action', 'component'),
        ];

        foreach ($exceptions as $exception) {
            $this->assertInstanceOf(RuntimeException::class, $exception);
        }
    }
}
