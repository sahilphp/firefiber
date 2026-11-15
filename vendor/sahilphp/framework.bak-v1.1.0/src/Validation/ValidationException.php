<?php

declare(strict_types=1);

namespace Sahil\Validation;

use RuntimeException;

/**
 * Raised when a caller prefers exception control flow over result
 * inspection. Carries the full {@see ValidationResult}; the HTTP kernel
 * treats it like any other fault (500) unless the application catches it
 * and renders 4xx semantics itself.
 */
final class ValidationException extends RuntimeException
{
    public function __construct(private readonly ValidationResult $result)
    {
        $summary = [];

        foreach ($result->errors() as $field => $messages) {
            $summary[] = "$field: ".implode(' / ', $messages);
        }

        parent::__construct('Validation failed: '.implode('; ', $summary));
    }

    public function result(): ValidationResult
    {
        return $this->result;
    }
}
