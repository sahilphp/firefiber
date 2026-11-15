<?php

declare(strict_types=1);

namespace Sahil\Notifications;

use Sahil\Contracts\Notifications\MailNotificationContract;
use Sahil\Contracts\Notifications\NotifiableContract;

/**
 * Base class for mail notifications.
 *
 * `via()` defaults to the `mail` channel; override it to target other
 * channels (and implement those channels' contracts) or to respect user
 * preferences. The mail body is composed in `toMail()` — the channel adds the
 * recipient and the configured sender.
 */
abstract class Notification implements MailNotificationContract
{
    /**
     * @return list<string>
     */
    public function via(NotifiableContract $notifiable): array
    {
        return ['mail'];
    }
}
