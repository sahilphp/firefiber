<?php

declare(strict_types=1);

namespace Sahil\Notifications;

use Sahil\Mail\MailReceipt;

/**
 * The outcome of one channel for one notification.
 *
 * Mail results carry the mail status through unchanged, so a queued
 * notification is reported as `queued` and an accepted one as `accepted` —
 * never as delivered.
 */
final class NotificationResult
{
    public const SKIPPED = 'skipped';

    public const FAILED = 'failed';

    private function __construct(
        public readonly string $channel,
        public readonly string $status,
        public readonly string $detail = '',
        public readonly ?MailReceipt $receipt = null,
    ) {
    }

    public static function fromReceipt(string $channel, MailReceipt $receipt): self
    {
        return new self($channel, $receipt->status->value, $receipt->detail, $receipt);
    }

    /**
     * Outcome for a non-mail channel that delivered successfully.
     */
    public static function success(string $channel, string $detail = ''): self
    {
        return new self($channel, 'sent', $detail);
    }

    public static function skipped(string $channel, string $detail): self
    {
        return new self($channel, self::SKIPPED, $detail);
    }

    public static function failed(string $channel, string $detail): self
    {
        return new self($channel, self::FAILED, $detail);
    }

    public function successful(): bool
    {
        return $this->status !== self::FAILED;
    }

    public function describe(): string
    {
        return sprintf('%s: %s%s', $this->channel, $this->status, $this->detail === '' ? '' : ' — '.$this->detail);
    }
}
