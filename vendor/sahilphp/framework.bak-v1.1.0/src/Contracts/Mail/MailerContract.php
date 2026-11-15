<?php

declare(strict_types=1);

namespace Sahil\Contracts\Mail;

use Sahil\Mail\MailReceipt;
use Sahil\Mail\Message;

/**
 * Application-facing mail API.
 *
 * Two delivery modes exist and they are deliberately different:
 *
 * - {@see send()} hands the message to a transport now and reports what the
 *   transport could observe (accepted by the server, suppressed, failed).
 *   It never touches the queue.
 * - {@see queue()} stores the message for later delivery. The returned
 *   receipt says `queued`; no transport has attempted the message yet, so a
 *   queued message must never be reported as sent or delivered.
 *
 * Queued delivery is optional: applications that never call {@see queue()}
 * need no queue worker and no queue connection.
 */
interface MailerContract
{
    /**
     * Send a message through a transport (the default one when null).
     *
     * The configured sender is applied when the message names none, and the
     * message is validated (sender, recipients, subject, body) before any
     * transport is contacted.
     *
     * @throws \Sahil\Mail\InvalidMessageException when the message cannot be sent
     * @throws \Sahil\Mail\TransportException      when the transport fails
     */
    public function send(Message $message, ?string $transport = null): MailReceipt;

    /**
     * Queue a message for later delivery.
     *
     * @param int $delay seconds before a worker may pick the message up
     *
     * @throws \Sahil\Mail\MailException when queued delivery is not enabled
     */
    public function queue(Message $message, int $delay = 0, ?string $transport = null): MailReceipt;

    /**
     * Resolve a transport by name (the default one when null).
     */
    public function transport(?string $name = null): TransportContract;
}
