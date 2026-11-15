<?php

declare(strict_types=1);

namespace Sahil\I18n;

/**
 * Message translation service.
 *
 * Keys are dotted: `group.path.to.key` where `group` names the translation
 * file (`lang/{locale}/{group}.php`) and the remainder walks the nested
 * array inside it. A key without a dot resolves inside the `messages` group.
 *
 * The service is bound in the application container as
 * {@see TranslatorContract::class}; the `__()`, `trans()` and
 * `trans_choice()` helpers resolve it.
 */
interface TranslatorContract
{
    /**
     * Translate a message key.
     *
     * `:name`-style placeholders in the message are replaced with the values
     * of `$replace` (every occurrence; missing values leave the placeholder
     * literal). When the key is absent in the active locale the configured
     * fallback locale is tried; when it is absent everywhere the key itself
     * is returned (and reported once through the missing-key hook).
     *
     * @param array<string, scalar> $replace
     */
    public function get(string $key, array $replace = [], ?string $locale = null): string;

    /**
     * Translate a message choosing its plural form by `$count` and the
     * active locale's plural rules.
     *
     * The message may be an array of forms keyed by plural category
     * (`['one' => ..., 'other' => ...]`) or a `|`-separated string:
     * `'one|other'`, optionally with explicit selectors
     * (`'{0} none|{1} one|[2,*] many'`). `:count` is always replaceable.
     *
     * @param array<string, scalar> $replace
     */
    public function choice(string $key, int|float $count, array $replace = [], ?string $locale = null): string;

    /**
     * Whether the key resolves to a message in the active or fallback locale.
     */
    public function has(string $key, ?string $locale = null): bool;

    /**
     * The locale messages are currently read in.
     */
    public function locale(): string;
}
