<?php

declare(strict_types=1);

namespace Sahil\Contracts\Notifications;

/**
 * Something that can receive notifications (usually a user model).
 *
 * Routing is channel-agnostic: a mail channel asks for `mail`, a future SMS
 * channel would ask for `sms`. Returning null means the notifiable has no
 * route for that channel, and the channel is skipped rather than failed.
 */
interface NotifiableContract
{
    /**
     * The address/identifier to use for a channel, or null when unavailable.
     */
    public function routeNotificationFor(string $channel): ?string;
}
