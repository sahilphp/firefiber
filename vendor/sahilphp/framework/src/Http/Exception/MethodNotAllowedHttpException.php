<?php

declare(strict_types=1);

namespace Sahil\Http\Exception;

/**
 * Thrown for requests whose method cannot be handled by the target.
 * Carries the `Allow` response header listing supported methods.
 */
final class MethodNotAllowedHttpException extends HttpException
{
    /**
     * @param list<string> $supportedMethods
     */
    public function __construct(
        array $supportedMethods,
        string $message = 'Unsupported HTTP method.',
        ?\Throwable $previous = null,
    ) {
        $unique = array_values(array_unique(array_map('strtoupper', $supportedMethods)));

        if ($unique === []) {
            throw new \InvalidArgumentException('MethodNotAllowedHttpException requires at least one supported method.');
        }

        sort($unique);

        parent::__construct(405, $message, ['Allow' => implode(', ', $unique)], $previous);
    }
}
