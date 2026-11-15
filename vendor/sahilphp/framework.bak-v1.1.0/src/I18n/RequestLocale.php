<?php

declare(strict_types=1);

namespace Sahil\I18n;

/**
 * Per-request locale override.
 *
 * Bound with `Container::scoped()`, so the container drops the instance when
 * the request scope ends — a long-running worker can therefore never leak
 * one request's locale into the next. {@see Locale} writes here whenever a
 * scope is active and falls back to the configured default otherwise.
 */
final class RequestLocale
{
    public ?string $locale = null;
}
