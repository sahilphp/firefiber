<?php

declare(strict_types=1);

namespace Sahil\Validation;

use Closure;
use InvalidArgumentException;
use Traversable;

/**
 * Deterministic rule-based validator.
 *
 * Construction:
 *
 *     $validator = new Validator([
 *         'email' => 'required|email',
 *         'age' => 'nullable|integer|min:0|max:130',
 *         'user.name' => 'required|string',
 *         'role' => 'in:admin,editor',
 *         'code' => 'regex:/^[A-Z]{3}$/',
 *         'tags.*' => 'string',
 *     ]);
 *
 * Conventions (documented as API contract):
 *
 *  - Fields not mentioned in the rule map NEVER reach `validated()`:
 *    validated data is the only "accepted" shape, raw input never is.
 *  - `required` fails for missing / null / empty-string fields.
 *  - `nullable` lets missing / null fields skip the whole chain; without
 *    `required` a missing field passes silently (validates to nothing).
 *  - An empty string is a PRESENT value: it is judged by the chain (so
 *    `numeric` rejects `""`), only `required` treats it as missing.
 *  - A failing field stops its own chain at the first failure (no
 *    cascading garbage messages), other fields keep validating.
 *  - Type rules check strictly; the ONLY coercion is documented and
 *    lossless: `integer` accepts int or plain digit strings → int,
 *    `numeric` accepts int/float/numeric strings (digit strings stay
 *    int when lossless, otherwise float), `boolean` accepts real bools
 *    or the exact strings "true"/"false" → bool. No trimming, no
 *    "looks like" heuristics beyond these cases.
 *  - Strings containing control characters (NUL, C0 controls except
 *    \t\n\r, DEL, C1) fail the `string` rule unless the SAME rule set
 *    also contains `allowsControlChars`.
 *  - Normalization happens ONLY when explicitly configured via
 *    {@see normalize()} (currently `trim`); default judges raw input.
 *
 * Wildcards (`tags.*`) clone the rules onto every element of the parent
 * array at that nesting level; nested dot paths reach array children.
 */
final class Validator
{
    private const CONTROL_PATTERN = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{80}-\x{9F}]/u';

    /** @var array<string, string|array<int, string|Closure|RuleContract>> */
    private array $rules;

    /** @var array<string, string> */
    private array $messages;

    /** @var list<string> */
    private array $normalizers = [];

    /**
     * @param array<string, string|array<int, string|Closure|RuleContract>> $rules
     * @param array<string, string> $messages custom messages; keys are
     *     `field.rule`, `field` or bare `rule`
     */
    public function __construct(array $rules, array $messages = [])
    {
        $this->rules = $rules;
        $this->messages = $messages;
    }

    /**
     * Explicit, opt-in normalization applied to input strings BEFORE
     * checks. Supported operations: `trim`. Default: none.
     *
     * @param list<string>|string $operations
     */
    public function normalize(array|string $operations): self
    {
        $operations = (array) $operations;
        $supported = ['trim'];

        foreach ($operations as $operation) {
            if (!in_array($operation, $supported, true)) {
                throw new InvalidArgumentException(
                    sprintf('Unknown input normalization [%s]; supported: %s.', $operation, implode(', ', $supported)),
                );
            }
        }

        $clone = clone $this;
        $clone->normalizers = $operations;

        return $clone;
    }

    /**
     * Run every rule over every field and collect all messages.
     *
     * @param array<string, mixed> $data
     */
    public function validate(array $data): ValidationResult
    {
        $errors = [];
        $validatedPaths = [];

        foreach ($this->expandedRules($data) as $path => $ruleSet) {
            $outcome = $this->runChain($path, $ruleSet, $data);

            if ($outcome['messages'] === []) {
                if ($outcome['hasOutputValue']) {
                    $validatedPaths[$path] = $outcome['value'];
                }

                continue;
            }

            $errors[$path] = $outcome['messages'];
        }

        return new ValidationResult($errors, $validatedPaths);
    }

    /**
     * Expand wildcard rules onto concrete array paths.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, list<string|Closure|RuleContract>>
     */
    private function expandedRules(array $data): array
    {
        $expanded = [];

        foreach ($this->rules as $path => $ruleSet) {
            $ruleList = $this->ruleList($ruleSet);

            if (!str_contains($path, '.*')) {
                $expanded[$path] = $ruleList;

                continue;
            }

            foreach ($this->explodeWildcard($path, $data) as $concrete) {
                $expanded[$concrete] = $ruleList;
            }
        }

        return $expanded;
    }

    /**
     * Turn `parent.*.child` into concrete paths by walking arrays; only
     * existing paths are produced (missing parents expand to nothing —
     * the lookup treats them as absent).
     *
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private function explodeWildcard(string $path, array $data): array
    {
        /** @var list<string> $concrete */
        $concrete = [''];
        $sawWildcard = false;

        foreach (explode('.', $path) as $segment) {
            $next = [];

            foreach ($concrete as $prefix) {
                if ($segment === '*') {
                    $sawWildcard = true;
                    $node = $prefix === '' ? $data : self::pathLookup($data, $prefix)[1];

                    if (is_array($node) || $node instanceof Traversable) {
                        foreach ($node as $key => $_) {
                            $next[] = $prefix === '' ? (string) $key : $prefix.'.'.$key;
                        }
                    }

                    continue;
                }

                $next[] = $prefix === '' ? $segment : $prefix.'.'.$segment;
            }

            $concrete = $next;
        }

        return $sawWildcard ? $concrete : [];
    }

    /**
     * @param string|array<int, mixed> $ruleSet elements are string
     *     descriptors, Closure or RuleContract; anything else fails loudly
     *
     * @return list<string|Closure|RuleContract>
     */
    private function ruleList(string|array $ruleSet): array
    {
        if (is_string($ruleSet)) {
            return array_values(array_filter(
                array_map(trim(...), explode('|', $ruleSet)),
                static fn (string $rule): bool => $rule !== '',
            ));
        }

        $rules = [];

        foreach ($ruleSet as $key => $rule) {
            if ($rule instanceof RuleContract) {
                $rules[] = $rule;

                continue;
            }

            if ($rule instanceof Closure) {
                $rules[] = $rule;

                continue;
            }

            if (is_string($rule)) {
                $rules[] = $rule;

                continue;
            }

            throw new InvalidArgumentException(
                sprintf('Validation rule for [%s] must be a string descriptor, Closure or RuleContract.', $key),
            );
        }

        return $rules;
    }

    /**
     * Run the rule chain for one concrete field path.
     *
     * @param list<string|Closure|RuleContract> $ruleSet
     *
     * @param array<string, mixed> $data
     *
     * @return array{messages: list<string>, hasOutputValue: bool, value: mixed}
     */
    private function runChain(string $path, array $ruleSet, array $data): array
    {
        [$exists, $value] = $this->pathLookup($data, $path);
        $missing = !$exists || $value === null;

        $required = in_array('required', $ruleSet, true);

        if ($missing) {
            if ($required) {
                return [
                    'messages' => [$this->message($path, 'required', 'This field is required.')],
                    'hasOutputValue' => false,
                    'value' => null,
                ];
            }

            // Optional missing/null fields pass, but validate to nothing.
            return ['messages' => [], 'hasOutputValue' => false, 'value' => null];
        }

        if ($value === '' && $required) {
            return [
                'messages' => [$this->message($path, 'required', 'This field is required.')],
                'hasOutputValue' => false,
                'value' => null,
            ];
        }

        // Explicit, configured normalization only.
        foreach ($this->normalizers as $operation) {
            if ($operation === 'trim' && is_string($value)) {
                $value = trim($value);
            }
        }

        $allowsControlChars = in_array('allowsControlChars', $ruleSet, true);
        $messages = [];

        foreach ($ruleSet as $rule) {
            if (is_string($rule)) {
                if ($rule === 'nullable' || $rule === 'required' || $rule === 'allowsControlChars') {
                    continue;
                }

                $outcome = $this->runBuiltIn($rule, $path, $value, $allowsControlChars);
            } elseif ($rule instanceof RuleContract) {
                $outcome = $rule->validate($path, $value);
            } else {
                /** @var Closure $rule */
                $outcome = $rule($path, $value);
            }

            if (is_array($outcome)) {
                /** @var array{0: ?string, 1: mixed} $outcome */
                $value = $outcome[1];
                $outcome = $outcome[0];
            }

            if (is_string($outcome)) {
                if (is_string($rule)) {
                    $ruleName = explode(':', $rule, 2)[0];
                    $outcome = $this->message($path, $ruleName, $outcome);
                }

                $messages[] = $outcome;

                break; // stop at the first failure for THIS field
            }
        }

        return [
            'messages' => $messages,
            'hasOutputValue' => $messages === [],
            'value' => $messages === [] ? $value : null,
        ];
    }

    /**
     * Built-in rules. Return types:
     *   ?string                        verdict only (null = pass)
     *   array{0: ?string, 1: mixed}    verdict + (possibly cast) validated value
     *
     * @return string|array{0: ?string, 1: mixed}|null
     */
    private function runBuiltIn(string $rule, string $path, mixed $value, bool $allowsControlChars): string|array|null
    {
        if (str_starts_with($rule, 'min:')) {
            return self::numericBound('min', $rule, $value);
        }

        if (str_starts_with($rule, 'max:')) {
            return self::numericBound('max', $rule, $value);
        }

        if (str_starts_with($rule, 'length:')) {
            return self::lengthRule($rule, $value);
        }

        if (str_starts_with($rule, 'in:')) {
            return self::membership(inclusion: true, rule: $rule, value: $value);
        }

        if (str_starts_with($rule, 'notIn:')) {
            return self::membership(inclusion: false, rule: $rule, value: $value);
        }

        if (str_starts_with($rule, 'regex:')) {
            return self::pattern($rule, $path, $value);
        }

        return match ($rule) {
            'string' => self::checkString($value, $allowsControlChars),
            'array' => is_array($value) ? null : 'This field must be an array.',
            'integer' => self::integerCast($value),
            'numeric' => self::numericCast($value),
            'boolean' => self::booleanCast($value),
            'email' => (is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false)
                ? null
                : 'This field must be a valid email address.',
            'url' => (is_string($value) && filter_var($value, FILTER_VALIDATE_URL) !== false)
                ? null
                : 'This field must be a valid URL.',
            default => throw new InvalidArgumentException(
                sprintf('Unknown validation rule [%s] on field [%s].', $rule, $path),
            ),
        };
    }

    /** @return ?string */
    private static function checkString(mixed $value, bool $allowsControlChars): ?string
    {
        if (!is_string($value)) {
            return 'This field must be a string.';
        }

        if (!$allowsControlChars && preg_match(self::CONTROL_PATTERN, $value) === 1) {
            return 'This field contains forbidden control characters.';
        }

        return null;
    }

    /**
     * Integer rule with the one documented lossless cast.
     *
     * @return array{0: ?string, 1: mixed}
     */
    private static function integerCast(mixed $value): array
    {
        if (is_int($value)) {
            return [null, $value];
        }

        if (is_string($value) && preg_match('/^[+-]?[0-9]+$/', $value) === 1) {
            $cast = (int) $value;

            // Lossless check: re-encoding the cast must reproduce the input.
            return ((string) $cast === $value) ? [null, $cast] : ['This field must be an integer.', $value];
        }

        return ['This field must be an integer.', $value];
    }

    /**
     * @return array{0: ?string, 1: mixed}
     */
    private static function numericCast(mixed $value): array
    {
        if (is_int($value) || is_float($value)) {
            return [null, $value];
        }

        if ($value === '') {
            return ['This field must be a number.', $value];
        }

        if (is_string($value) && is_numeric($value)) {
            $cast = $value + 0;

            if ((string) $cast === $value) {
                return [null, $cast]; // lossless digit-string → int
            }

            return [null, $cast]; // numeric text → numeric value ('007' → 7.0)
        }

        return ['This field must be a number.', $value];
    }

    /**
     * @return array{0: ?string, 1: mixed}
     */
    private static function booleanCast(mixed $value): array
    {
        if (is_bool($value)) {
            return [null, $value];
        }

        if (is_string($value) && in_array($value, ['true', 'false'], true)) {
            return [null, $value === 'true'];
        }

        return ['This field must be a boolean.', $value];
    }

    /**
     * Numeric bounds. Skips silently when the value is not numeric yet
     * (the type rules own that failure and their message stands).
     */
    private static function numericBound(string $bound, string $rule, mixed $value): ?string
    {
        $rawBound = substr($rule, 4);

        if ($rawBound === '' || !is_numeric($rawBound)) {
            throw new InvalidArgumentException(
                sprintf('Validation rule [%s] needs a numeric argument.', $rule),
            );
        }

        if (!is_int($value) && !is_float($value)) {
            return null;
        }

        $boundValue = $rawBound + 0.0;

        if (($bound === 'min' && $value + 0.0 < $boundValue)
            || ($bound === 'max' && $value + 0.0 > $boundValue)) {
            return $bound === 'min'
                ? sprintf('This field must be greater than or equal to %s.', $rawBound)
                : sprintf('This field must be less than or equal to %s.', $rawBound);
        }

        return null;
    }

    /** @return ?string */
    private static function lengthRule(string $rule, mixed $value): ?string
    {
        if (!is_string($value) && !is_array($value)) {
            return null;
        }

        $bounds = substr($rule, 7);

        if ($bounds === '' || preg_match('/^(\d+)(?:,(\d+))?$/', $bounds) !== 1) {
            throw new InvalidArgumentException(
                sprintf('Validation rule [%s] needs `length:min` or `length:min,max`.', $rule),
            );
        }

        [$min, $max] = array_pad(explode(',', $bounds), 2, null);
        $length = is_string($value) ? mb_strlen($value) : count($value);

        if ($min !== null && $length < (int) $min) {
            return sprintf('This field must have at least %d characters.', (int) $min);
        }

        if ($max !== null && $max !== '' && $length > (int) $max) {
            return sprintf('This field must have at most %d characters.', (int) $max);
        }

        return null;
    }

    /** @return ?string */
    private static function membership(bool $inclusion, string $rule, mixed $value): ?string
    {
        $prefix = $inclusion ? 3 : 6;
        $rawList = substr($rule, $prefix);

        if ($rawList === '') {
            throw new InvalidArgumentException(
                sprintf('Validation rule [%s] needs at least one value.', $rule),
            );
        }

        $allowed = array_map(trim(...), explode(',', $rawList));

        $asText = is_string($value) ? $value : (is_scalar($value) ? (string) $value : null);

        if ($asText === null) {
            return sprintf('Allowed values: %s.', implode(', ', $allowed));
        }

        $found = in_array($asText, $allowed, true);

        if ($inclusion && !$found) {
            return sprintf('This field must be one of: %s.', implode(', ', $allowed));
        }

        if (!$inclusion && $found) {
            return sprintf('This field must not be one of: %s.', implode(', ', $allowed));
        }

        return null;
    }

    /** @return ?string */
    private static function pattern(string $rule, string $path, mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $pattern = substr($rule, 6);

        if (strlen($pattern) < 2 || $pattern[0] !== $pattern[-1]) {
            throw new InvalidArgumentException(
                sprintf('regex rule on [%s] needs a delimited PCRE pattern, got [%s].', $path, $pattern),
            );
        }

        if (in_array('e', self::modifiers($pattern), true)) {
            throw new InvalidArgumentException(
                sprintf('regex rule on [%s] uses the forbidden PCRE "e" modifier.', $path),
            );
        }

        return preg_match($pattern, $value) === 1 ? null : 'This field format is not allowed.';
    }

    /** @return list<string> */
    private static function modifiers(string $pattern): array
    {
        $closing = strrpos($pattern, $pattern[0]);

        if ($closing === false || $closing < 1) {
            return [];
        }

        $modifiers = substr($pattern, $closing + 1);

        return $modifiers === '' ? [] : str_split($modifiers);
    }

    /**
     * Dotted lookup. Returns [exists, value]; a scalar parent makes the
     * nested field absent on principle.
     *
     * @param array<string, mixed> $data
     *
     * @return array{0: bool, 1: mixed}
     */
    private static function pathLookup(array $data, string $path): array
    {
        $current = $data;

        foreach (explode('.', $path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return [false, null];
            }

            $current = $current[$segment];
        }

        return [true, $current];
    }

    /**
     * Message lookup: `field.rule` → `field` → bare `rule` → default.
     */
    private function message(string $path, string $ruleName, string $default): string
    {
        return $this->messages[$path.'.'.$ruleName]
            ?? $this->messages[$path]
            ?? $this->messages[$ruleName]
            ?? $default;
    }
}
