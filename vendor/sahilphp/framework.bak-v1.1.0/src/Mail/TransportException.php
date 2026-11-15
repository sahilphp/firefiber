<?php

declare(strict_types=1);

namespace Sahil\Mail;

/**
 * A transport (SMTP server, provider API) failed or refused the message.
 *
 * The exception message is sanitised before it leaves the transport: SMTP
 * AUTH payloads, passwords and DSN credentials are removed, because transport
 * errors are logged and printed.
 */
final class TransportException extends MailException
{
}
