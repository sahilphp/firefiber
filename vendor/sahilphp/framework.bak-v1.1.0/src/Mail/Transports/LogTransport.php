<?php

declare(strict_types=1);

namespace Sahil\Mail\Transports;

use Sahil\Contracts\Mail\TransportContract;
use Sahil\Mail\MailReceipt;
use Sahil\Mail\Message;

/**
 * Development transport: deliberately transmits nothing.
 *
 * The receipt says `suppressed`, so nothing in the system can mistake it for a
 * delivery. The mailer records the attempt in the structured log as a summary
 * (transport, status, recipient count) — never the subject, body or recipient
 * addresses, because application logs must not absorb message content. Use
 * the `array` transport in tests and `smtp` (or a provider transport) in
 * production.
 */
final class LogTransport implements TransportContract
{
    public function __construct(private readonly string $name = 'log')
    {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function send(Message $message): MailReceipt
    {
        $message->assertSendable();

        return MailReceipt::suppressed(
            $this->name,
            'Not transmitted: the ['.$this->name.'] transport only records the attempt. Configure [smtp] to send.',
        );
    }
}
