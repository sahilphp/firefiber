<?php

declare(strict_types=1);

namespace Sahil\Firefiber\Exceptions;

use RuntimeException;
use Throwable;

class UnknownActionException extends RuntimeException
{
    public function __construct(string $action = '', ?string $componentId = null, string $message = '', int $code = 0, ?Throwable $previous = null)
    {
        if ($message === '') {
            $message = $action === '' ? 'Unknown action' : 'Unknown action: ' . $action;
            if ($componentId !== null && $componentId !== '') {
                $message .= ' for component ' . $componentId;
            }
        }
        parent::__construct($message, $code, $previous);
    }
}
