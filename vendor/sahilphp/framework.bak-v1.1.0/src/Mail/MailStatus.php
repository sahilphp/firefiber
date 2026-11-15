<?php

declare(strict_types=1);

namespace Sahil\Mail;

/**
 * What happened to a message, as far as the framework can actually observe.
 *
 * The states are ordered by how much they claim, and the distinction matters:
 *
 * | State       | Meaning                                                        | Who reports it                |
 * |-------------|----------------------------------------------------------------|-------------------------------|
 * | `Queued`    | Stored for later delivery; no transport has been contacted.   | {@see MailManager::queue()}   |
 * | `Accepted`  | The next hop (SMTP server / provider) acknowledged the message. | SMTP transport                |
 * | `Sent`      | A provider confirms it took the message and returned an id.   | API transports (future)       |
 * | `Delivered` | The receiving mailbox has confirmed delivery.                 | provider feedback (future)    |
 * | `Suppressed`| Deliberately not transmitted (log/array transports).           | log/local transports          |
 * | `Failed`    | The transport refused or failed; nothing was accepted.         | any transport                 |
 *
 * `Accepted` is not `Delivered`: an SMTP `250` after `DATA` means the server
 * took responsibility for the message, not that it reached a mailbox.
 */
enum MailStatus: string
{
    case Queued = 'queued';
    case Accepted = 'accepted';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Suppressed = 'suppressed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'queued for delivery',
            self::Accepted => 'accepted by the next hop',
            self::Sent => 'sent to the provider',
            self::Delivered => 'delivery confirmed',
            self::Suppressed => 'suppressed (not transmitted)',
            self::Failed => 'failed',
        };
    }

    /** Whether this state represents a delivery problem. */
    public function isFailure(): bool
    {
        return $this === self::Failed;
    }

    /** Whether the message is still waiting for a transport attempt. */
    public function isPending(): bool
    {
        return $this === self::Queued;
    }
}
