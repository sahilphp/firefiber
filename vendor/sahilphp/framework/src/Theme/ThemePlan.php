<?php

declare(strict_types=1);

namespace Sahil\Theme;

use Sahil\Ui\Profile;

/**
 * Everything `ui:theme` would write for one profile.
 *
 * `generated` files are owned by the generator and may be replaced on every
 * run. `user` files are created once and then preserved, so application
 * overrides survive regeneration. `entries` contains the marker-delimited
 * import block to splice into an existing stylesheet entry.
 */
final class ThemePlan
{
    /**
     * @param array<string, string> $generated project-relative path => content
     * @param array<string, string> $user      project-relative path => initial content
     * @param array<string, string> $entries   project-relative path => marker block
     */
    public function __construct(
        public readonly Profile $profile,
        public readonly ThemeTokens $tokens,
        public readonly array $generated,
        public readonly array $user,
        public readonly array $entries,
    ) {
    }
}
