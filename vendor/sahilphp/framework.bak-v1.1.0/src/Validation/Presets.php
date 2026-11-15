<?php

declare(strict_types=1);

namespace Sahil\Validation;

use Closure;

/**
 * Reusable validation definitions (presets).
 *
 * Application code registers named rule maps once (typically in a boot
 * provider) and reuses them everywhere:
 *
 *     Validator::definePreset('user.signup', [
 *         'email' => 'required|email',
 *         'password' => 'required|string|length:8,128',
 *     ]);
 *
 *     $result = Validator::preset('user.signup')->validate($payload);
 *
 * Presets are copied on read: callers cannot mutate the registered form.
 */
final class Presets
{
    /** @var array<string, array<string, string|array<int, string|Closure|RuleContract>>> */
    private static array $presets = [];

    /**
     * @param array<string, string|array<int, string|Closure|RuleContract>> $rules
     */
    public static function define(string $name, array $rules): void
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9._-]*$/', $name) !== 1) {
            throw new \InvalidArgumentException(
                sprintf('Validator preset name [%s] must be a word-token.', $name),
            );
        }

        self::$presets[$name] = $rules;
    }

    /**
     * @return array<string, string|array<int, string|Closure|RuleContract>>
     */
    public static function get(string $name): array
    {
        if (!isset(self::$presets[$name])) {
            throw new \InvalidArgumentException(
                sprintf('Validator preset [%s] is not defined.', $name),
            );
        }

        return self::$presets[$name];
    }

    /**
     * Build a validator straight from a preset.
     *
     * @param array<string, string> $messages
     */
    public static function validator(string $name, array $messages = []): Validator
    {
        return new Validator(self::get($name), $messages);
    }

    public static function reset(): void
    {
        self::$presets = [];
    }
}
