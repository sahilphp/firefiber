<?php

declare(strict_types=1);

namespace Sahil\Mail\Jobs;

use Sahil\Contracts\Mail\MailerContract;
use Sahil\Mail\MailException;
use Sahil\Mail\MailReceipt;
use Sahil\Mail\Message;
use Sahil\Queue\Jobs\Job;

/**
 * Deferred delivery: one queued message, sent by a worker.
 *
 * The job holds the fully composed {@see Message} (plain data only, so it
 * serializes safely) and the transport name chosen when it was queued. A
 * transport failure throws, which is what makes the worker retry the job —
 * retries are at-least-once, so a message can in theory be delivered twice
 * when a failure happens after the server accepted it. Application code that
 * cannot tolerate duplicates should deduplicate on its own idempotency key,
 * e.g. with a custom header.
 *
 * Delivery is not claimed here: the job returns the transport's receipt, and
 * a worker that completes only proves the transport accepted the message (or
 * suppressed it), not that a mailbox received it.
 */
final class SendMailJob extends Job
{
    public function __construct(
        private readonly Message $message,
        private readonly ?string $transport = null,
    ) {
    }

    public function handle(): MailReceipt
    {
        /** @var MailerContract $mailer */
        $mailer = app()->container()->make(MailerContract::class);

        $receipt = $mailer->send($this->message, $this->transport);

        if ($receipt->status->isFailure()) {
            throw new MailException(sprintf('Mail transport [%s] failed to send the queued message.', $receipt->transport));
        }

        return $receipt;
    }

    public function message(): Message
    {
        return $this->message;
    }

    public function transportName(): ?string
    {
        return $this->transport;
    }
}
