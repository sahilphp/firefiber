<?php

declare(strict_types=1);

namespace Sahil\Contracts\Notifications;

use Sahil\Mail\Message;

/**
 * A notification that can be delivered over the `mail` channel.
 *
 * The notification composes the message; adding the recipient and applying
 * the default sender is the mail channel's job.
 */
interface MailNotificationContract extends NotificationContract
{
    public function toMail(NotifiableContract $notifiable): Message;
}
