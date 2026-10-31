<?php

declare(strict_types=1);

namespace Sahil\Notifications;

use Sahil\Contracts\Notifications\ChannelContract;
use Sahil\Contracts\Notifications\NotifiableContract;
use Sahil\Contracts\Notifications\NotificationContract;
use Sahil\Mail\Sanitizer;
use Sahil\Support\Logging\LoggerContract;
use Throwable;

/**
 * Routes a notification to the channels it asks for.
 *
 * Channels are registered by name, so `mail` ships today and further channels
 * (SMS, chat, push) can be added with {@see extend()} without touching
 * notifications or this class. Each channel is delivered independently: a
 * failure is logged without content, recorded in the report, and the
 * remaining channels still run.
 */
final class Notifier
{
    /** @var array<string, ChannelContract> */
    private array $channels = [];

    /**
     * @param iterable<ChannelContract> $channels
     */
    public function __construct(iterable $channels = [], private readonly ?LoggerContract $logger = null)
    {
        foreach ($channels as $channel) {
            $this->extend($channel);
        }
    }

    public function extend(ChannelContract $channel): void
    {
        $this->channels[$channel->name()] = $channel;
    }

    public function channel(string $name): ChannelContract
    {
        if (!isset($this->channels[$name])) {
            throw new NotificationException(sprintf(
                'Notification channel [%s] is not registered; available: %s.',
                $name,
                $this->channels === [] ? '(none)' : implode(', ', array_keys($this->channels)),
            ));
        }

        return $this->channels[$name];
    }

    /**
     * @return array<string, ChannelContract>
     */
    public function channels(): array
    {
        return $this->channels;
    }

    public function send(NotifiableContract $notifiable, NotificationContract $notification): NotificationReport
    {
        $report = new NotificationReport();

        foreach (array_unique($notification->via($notifiable)) as $name) {
            // An unknown channel is a wiring bug: fail loudly instead of
            // silently dropping the notification.
            $channel = $this->channel($name);

            try {
                $report->add($channel->send($notifiable, $notification));
            } catch (Throwable $failure) {
                $detail = Sanitizer::error($failure->getMessage());

                $this->logger?->error('notification.failed', [
                    'channel' => $name,
                    'notification' => $notification::class,
                    'exception' => $failure::class,
                    'error' => $detail,
                ]);

                $report->add(NotificationResult::failed($name, $detail));
            }
        }

        return $report;
    }
}
