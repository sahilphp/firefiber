<?php

declare(strict_types=1);

namespace Sahil\Contracts\Mail;

use Sahil\Mail\MailReceipt;
use Sahil\Mail\Message;

/**
 * A single mail transport (SMTP, a provider API, a local log, ...).
 *
 * This is the framework's mail extension point: everything above it (the
 * mailer, notifications, queued jobs) only ever talks to this contract, so a
 * new provider is one class plus one configuration entry.
 *
 * Implementations MUST return a receipt that states what actually happened.
 * A transport may report that a remote server accepted a message, but it
 * must never report delivery unless it has been confirmed by the receiving
 * side; see {@see \Sahil\Mail\MailStatus}.
 */
interface TransportContract
{
    /** The configured transport name (the key under `mail.transports`). */
    public function name(): string;

    /**
     * Transmit the message.
     *
     * @throws \Sahil\Mail\TransportException when the transport fails
     */
    public function send(Message $message): MailReceipt;
}
