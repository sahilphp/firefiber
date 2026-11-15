<?php

declare(strict_types=1);

namespace Sahil\Frontend;

/**
 * Renderer settings that are not adapter-specific.
 *
 * Kept as a small validated value object instead of reading configuration
 * from inside the renderer: tests and applications can construct one
 * directly, and an invalid value fails at construction with a clear message.
 */
final class FrontendConfiguration
{
    public function __construct(
        private readonly string $mountId = 'app',
        private readonly string $titleKey = 'title',
        private readonly string $lang = 'en',
        private readonly string $defaultTitle = 'SahilPHP',
        private readonly bool $development = true,
    ) {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $this->mountId) !== 1) {
            throw new InvalidFrontendConfigurationException(sprintf(
                'Invalid frontend mount id [%s]; use letters, digits, underscores and dashes, starting with a letter.',
                $this->mountId,
            ));
        }

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $this->titleKey) !== 1) {
            throw new InvalidFrontendConfigurationException(sprintf(
                'Invalid frontend title key [%s]; use a data key such as "title".',
                $this->titleKey,
            ));
        }

        if (preg_match('/^[A-Za-z]{2,8}(-[A-Za-z0-9]{2,8})*$/', $this->lang) !== 1) {
            throw new InvalidFrontendConfigurationException(sprintf('Invalid frontend language [%s].', $this->lang));
        }
    }

    /**
     * @param array<string, mixed> $configuration the `frontend` config namespace
     */
    public static function fromArray(array $configuration): self
    {
        $string = static function (string $key, string $default) use ($configuration): string {
            $value = $configuration[$key] ?? $default;

            if (!is_string($value)) {
                throw new InvalidFrontendConfigurationException(sprintf(
                    'frontend.%s must be a string, got %s.',
                    $key,
                    get_debug_type($value),
                ));
            }

            return $value;
        };

        $development = $configuration['development'] ?? true;

        if (!is_bool($development)) {
            throw new InvalidFrontendConfigurationException(sprintf(
                'frontend.development must be a boolean, got %s.',
                get_debug_type($development),
            ));
        }

        return new self(
            $string('mount_id', 'app'),
            $string('title_key', 'title'),
            $string('lang', 'en'),
            $string('default_title', 'SahilPHP'),
            $development,
        );
    }

    public function mountId(): string
    {
        return $this->mountId;
    }

    public function titleKey(): string
    {
        return $this->titleKey;
    }

    public function lang(): string
    {
        return $this->lang;
    }

    public function defaultTitle(): string
    {
        return $this->defaultTitle;
    }

    /**
     * Whether adapters should resolve development-server assets.
     */
    public function development(): bool
    {
        return $this->development;
    }
}
