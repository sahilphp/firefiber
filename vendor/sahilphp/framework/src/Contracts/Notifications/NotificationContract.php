<?php

declare(strict_types=1);

namespace Sahil\Contracts\Notifications;

/**
 * A message that can travel over one or more channels.
 *
 * A notification only names the channels it wants. What to send on a given
 * channel is declared by the channel-specific contract, e.g.
 * {@see MailNotificationContract} for mail, so a future channel can be added
 * without changing the notification base class.
 */
interface NotificationContract
{
    /**
     * Channels this notification requests for the given notifiable.
     *
     * Unknown channel names are a configuration error and are reported as
     * such; an empty list means "send nothing" (an opted-out user).
     *
     * @return list<string>
     */
    public function via(NotifiableContract $notifiable): array;
}
