<?php

declare(strict_types=1);

namespace Sahil\Validation;

/**
 * A custom validation rule.
 *
 * The validator calls `validate()` with the (fully addressed) dotted field
 * path and its value; return `null` to pass, or a human message string to
 * fail. Fail early but do not abort the whole run — the validator decides
 * which further rules are still meaningful.
 */
interface RuleContract
{
    public function validate(string $field, mixed $value): ?string;
}
