<?php

declare(strict_types=1);

namespace Sahil\View;

use Throwable;

/**
 * A template that compiles but cannot be rendered: emitting a structure
 * whose string form is ambiguous, looping over something that is not
 * iterable, `@extends` outside a top-level template, and so on.
 *
 * Runtime failures are never swallowed: whatever a template throws is
 * re-thrown wrapped in this type with the template name attached, so the
 * original throwable stays reachable through `getPrevious()`.
 */
final class ViewRenderException extends ViewException
{
    public static function forTemplate(string $template, Throwable $failure): self
    {
        return new self(
            sprintf('Rendering view [%s] failed: %s', $template, $failure->getMessage()),
            0,
            $failure,
        );
    }
}
