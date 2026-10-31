<?php

declare(strict_types=1);

namespace Sahil\Firefiber\Exceptions;

use RuntimeException;

/**
 * Raised when a submitted model value fails component validation.
 *
 * The middleware converts this into the protocol's validation error response
 * (HTTP 422, errorCode 1) and never persists the rejected state.
 */
final class ValidationException extends RuntimeException
{
    /** @var array<string, list<string>> */
    private array $errors;

    /**
     * @param array<string, list<string>> $errors Field-keyed validation messages
     */
    public function __construct(string $message = 'Validation failed', array $errors = [])
    {
        parent::__construct($message);

        $this->errors = $errors;
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }
}
