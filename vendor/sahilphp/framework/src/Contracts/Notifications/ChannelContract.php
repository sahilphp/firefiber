<?php

declare(strict_types=1);

namespace Sahil\Contracts\Notifications;

use Sahil\Notifications\NotificationResult;

/**
 * One delivery channel for notifications (mail, SMS, chat, ...).
 *
 * Channels are registered on the {@see \Sahil\Notifications\Notifier} by
 * name, so a notification can ask for `mail` today and `sms` tomorrow
 * without the notifier or the notification base class changing.
 */
interface ChannelContract
{
    /** The channel name used in {@see NotificationContract::via()}. */
    public function name(): string;

    /**
     * Deliver one notification.
     *
     * Returning a result with status `skipped` is how a channel says the
     * notifiable has no route for it; throwing means the delivery failed.
     */
    public function send(NotifiableContract $notifiable, NotificationContract $notification): NotificationResult;
}
