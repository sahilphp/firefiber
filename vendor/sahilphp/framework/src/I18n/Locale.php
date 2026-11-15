<?php

declare(strict_types=1);

namespace Sahil\I18n;

use InvalidArgumentException;
use Sahil\Config\Repository;
use Sahil\Container\Container;

/**
 * Active-locale manager.
 *
 * The configured application locale (`app.locale`, `sw` by default) is the
 * baseline. `set()` inside an HTTP request writes the request-scoped
 * {@see RequestLocale} — it vanishes with the request scope, so workers
 * cannot leak locale state between requests. Outside a request scope (CLI,
 * tests) `set()` writes the process slot used by that single operation.
 *
 * Public API is `app()->getLocale()` / `app()->setLocale()`, which delegate
 * here; this class stays the single place that knows where the active
 * locale lives.
 */
final class Locale
{
    /**
     * Out-of-scope locale, used by console commands and standalone callers.
     */
    private ?string $processLocale = null;

    public function __construct(
        private readonly Container $container,
        private readonly Repository $config,
    ) {
    }

    /**
     * The configured application locale — `sw` unless the application
     * chooses otherwise.
     */
    public function default(): string
    {
        return self::normalize((string) $this->config->get('app.locale', 'sw')) ?: 'sw';
    }

    /**
     * The locale consulted when a message is missing in the active one.
     */
    public function fallback(): string
    {
        return self::normalize((string) $this->config->get('app.fallback_locale', $this->default())) ?: $this->default();
    }

    /**
     * Every locale the application may be switched into: the configured
     * `app.available_locales` plus the default and fallback locales (a
     * misconfigured list can never exclude the app's own default).
     *
     * @return list<string>
     */
    public function supported(): array
    {
        /** @var mixed $configured */
        $configured = $this->config->get('app.available_locales', [$this->default()]);

        if (is_string($configured)) {
            $configured = explode(',', $configured);
        }

        $locales = [];

        if (is_array($configured)) {
            foreach ($configured as $locale) {
                $locale = self::normalize((string) $locale);

                if ($locale !== '') {
                    $locales[$locale] = true;
                }
            }
        }

        $locales[$this->default()] = true;
        $locales[$this->fallback()] = true;

        return array_keys($locales);
    }

    public function isSupported(string $locale): bool
    {
        return in_array(self::normalize($locale), $this->supported(), true);
    }

    /**
     * The locale the current execution should read messages in.
     */
    public function current(): string
    {
        if ($this->container->currentScope() !== null) {
            // Inside a request only the request's own selection or the
            // configured default applies — the out-of-scope process slot is
            // for console work and must never bleed into requests.
            return $this->requestLocale()->locale ?? $this->default();
        }

        return $this->processLocale ?? $this->default();
    }

    /**
     * Activate a supported locale for the current request scope (or, outside
     * a scope, for the current process operation).
     *
     * @throws InvalidArgumentException when the locale is not in
     *         `app.available_locales` (with the default/fallback included).
     */
    public function set(string $locale): void
    {
        $locale = self::normalize($locale);

        if (!$this->isSupported($locale)) {
            throw new InvalidArgumentException(sprintf(
                'Locale [%s] is not in the supported list [%s]. Add it to app.available_locales first.',
                $locale,
                implode(', ', $this->supported()),
            ));
        }

        if ($this->container->currentScope() !== null) {
            $this->requestLocale()->locale = $locale;

            return;
        }

        $this->processLocale = $locale;
    }

    /**
     * Like {@see set()} but silently declines unsupported locales — the
     * request-locale middleware's safe path for unvalidated input.
     */
    public function setIfSupported(string $locale): bool
    {
        if (!$this->isSupported($locale)) {
            return false;
        }

        $this->set($locale);

        return true;
    }

    /**
     * Forget any override and return to the configured default. Inside a
     * request scope this clears the request's selection; outside it clears
     * the process slot.
     */
    public function clear(): void
    {
        if ($this->container->currentScope() !== null) {
            $this->requestLocale()->locale = null;

            return;
        }

        $this->processLocale = null;
    }

    private function requestLocale(): RequestLocale
    {
        $resolved = $this->container->make(RequestLocale::class);
        \assert($resolved instanceof RequestLocale);

        return $resolved;
    }

    /**
     * Accept `en-US`/`en_US` spellings while keeping `en` canonical for
     * matching and file lookup.
     */
    private static function normalize(string $locale): string
    {
        return str_replace('_', '-', strtolower(trim($locale)));
    }
}
