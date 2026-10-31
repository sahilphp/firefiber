<?php

declare(strict_types=1);

namespace Sahil\View;

/**
 * A template that cannot be compiled: unknown/invalid directive arguments,
 * unbalanced blocks, a malformed expression, or a forbidden PHP tag.
 *
 * Always carries the template name and the LINE of the offending syntax, so
 * the message points at the exact place to fix:
 *
 *     ViewSyntaxException: Unclosed @if block ... in posts/show on line 12
 */
final class ViewSyntaxException extends ViewException
{
    public function __construct(
        private readonly string $template,
        private readonly int $templateLine,
        string $reason,
    ) {
        parent::__construct(sprintf('%s in [%s] on line %d.', $reason, $template, $templateLine));
    }

    public function template(): string
    {
        return $this->template;
    }

    /**
     * Line of the template the error was found on (named to avoid clashing
     * with `Exception::$line`, which points into the compiled file).
     */
    public function line(): int
    {
        return $this->templateLine;
    }
}
