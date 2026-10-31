<?php

declare(strict_types=1);

namespace Sahil\Mail;

use RuntimeException;

/**
 * Base exception for mail problems: configuration, transport and message
 * errors. Not part of the HTTP exception tree — mail failures are operational
 * failures, not response codes.
 */
class MailException extends RuntimeException
{
}
