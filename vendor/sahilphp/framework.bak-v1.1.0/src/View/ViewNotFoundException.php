<?php

declare(strict_types=1);

namespace Sahil\View;

/**
 * Thrown when a template name cannot be resolved to a file.
 *
 * Lists every directory that was searched, so a typo or a misconfigured
 * view root is obvious instead of mysterious.
 */
final class ViewNotFoundException extends ViewException
{
    /**
     * @param list<string> $searched directories, in search order
     */
    public function __construct(
        private readonly string $template,
        array $searched,
    ) {
        parent::__construct(sprintf(
            'View [%s] not found. Searched: %s.',
            $template,
            $searched === [] ? '(no view directories configured)' : implode(', ', $searched),
        ));
    }

    public function template(): string
    {
        return $this->template;
    }
}
