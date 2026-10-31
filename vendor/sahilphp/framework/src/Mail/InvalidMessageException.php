<?php

declare(strict_types=1);

namespace Sahil\Mail;

/**
 * The message itself is unusable: no sender, no recipients, an invalid
 * address, a header that would inject lines, or no body.
 *
 * Raised before any transport is contacted, so a malformed message can never
 * reach the network.
 */
final class InvalidMessageException extends MailException
{
}
