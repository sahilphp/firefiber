<?php

declare(strict_types=1);

namespace Sahil\Mail;

/**
 * The observable outcome of a mail operation.
 *
 * A receipt never upgrades itself: a queued message stays `Queued` until a
 * transport actually runs, and an SMTP acknowledgement stays `Accepted` —
 * delivery is only claimed when a transport truly observes it.
 */
final class MailReceipt
{
    private function __construct(
        public readonly MailStatus $status,
        public readonly string $transport,
        public readonly ?string $messageId = null,
        public readonly ?string $queueId = null,
        public readonly string $detail = '',
    ) {
    }

    public static function queued(string $transport, ?string $queueId = null): self
    {
        return new self(
            MailStatus::Queued,
            $transport,
            null,
            $queueId,
            'Stored for later delivery; no transport has attempted it yet.',
        );
    }

    public static function accepted(string $transport, ?string $messageId = null): self
    {
        return new self(
            MailStatus::Accepted,
            $transport,
            $messageId,
            null,
            'Accepted by the next hop for delivery; delivery is not confirmed.',
        );
    }

    public static function sent(string $transport, ?string $messageId = null): self
    {
        return new self(
            MailStatus::Sent,
            $transport,
            $messageId,
            null,
            'Handed to the provider, which took responsibility for delivery.',
        );
    }

    public static function delivered(string $transport, ?string $messageId = null): self
    {
        return new self(
            MailStatus::Delivered,
            $transport,
            $messageId,
            null,
            'Delivery to the recipient was confirmed.',
        );
    }

    public static function suppressed(string $transport, string $detail): self
    {
        return new self(MailStatus::Suppressed, $transport, null, null, $detail);
    }

    public static function failed(string $transport, string $detail): self
    {
        return new self(MailStatus::Failed, $transport, null, null, $detail);
    }

    public function successful(): bool
    {
        return !$this->status->isFailure();
    }

    /**
     * One human-readable line, honest about what is known.
     */
    public function describe(): string
    {
        $parts = [sprintf('%s via [%s]', $this->status->label(), $this->transport)];

        if ($this->messageId !== null && $this->messageId !== '') {
            $parts[] = sprintf('message id %s', $this->messageId);
        }

        if ($this->queueId !== null && $this->queueId !== '') {
            $parts[] = sprintf('queue id %s', $this->queueId);
        }

        $parts[] = $this->detail;

        return trim(implode(' — ', array_filter($parts, static fn (string $part): bool => $part !== '')));
    }
}
