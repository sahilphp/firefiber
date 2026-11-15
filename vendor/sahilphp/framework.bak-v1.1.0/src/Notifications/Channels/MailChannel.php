<?php

declare(strict_types=1);

namespace Sahil\Notifications\Channels;

use Sahil\Contracts\Mail\MailerContract;
use Sahil\Contracts\Notifications\ChannelContract;
use Sahil\Contracts\Notifications\MailNotificationContract;
use Sahil\Contracts\Notifications\NotifiableContract;
use Sahil\Contracts\Notifications\NotificationContract;
use Sahil\Notifications\NotificationException;
use Sahil\Notifications\NotificationResult;

/**
 * Delivers a notification over mail.
 *
 * The channel owns transport policy: with `$queued` true it hands the message
 * to the mail queue and reports `queued`; otherwise it sends synchronously and
 * reports the transport's receipt. The notification only composes the
 * message. When the message names no recipient, the notifiable's `mail` route
 * is added; an explicitly addressed message is left alone.
 */
final class MailChannel implements ChannelContract
{
    public function __construct(
        private readonly MailerContract $mailer,
        private readonly bool $queued = false,
        private readonly int $delay = 0,
    ) {
    }

    public function name(): string
    {
        return 'mail';
    }

    public function send(NotifiableContract $notifiable, NotificationContract $notification): NotificationResult
    {
        if (!$notification instanceof MailNotificationContract) {
            throw new NotificationException(sprintf(
                'Notification [%s] asks for the [mail] channel but does not implement %s.',
                $notification::class,
                MailNotificationContract::class,
            ));
        }

        $route = $notifiable->routeNotificationFor('mail');

        if ($route === null || trim($route) === '') {
            return NotificationResult::skipped('mail', 'The notifiable has no mail route.');
        }

        $message = $notification->toMail($notifiable);

        if (!$message->hasRecipients()) {
            $message->to($route);
        }

        $receipt = $this->queued
            ? $this->mailer->queue($message, $this->delay)
            : $this->mailer->send($message);

        return NotificationResult::fromReceipt('mail', $receipt);
    }
}
