<?php

declare(strict_types=1);

namespace Sahil\Mail;

use Closure;
use Sahil\Contracts\Mail\MailerContract;
use Sahil\Contracts\Mail\TransportContract;
use Sahil\Contracts\Queue\DispatcherContract;
use Sahil\Mail\Jobs\SendMailJob;
use Sahil\Mail\Transports\ArrayTransport;
use Sahil\Mail\Transports\LogTransport;
use Sahil\Mail\Transports\SmtpTransport;
use Sahil\Support\Logging\LoggerContract;
use Throwable;

/**
 * Resolves transports from configuration and owns the send/queue decision.
 *
 * A transport is built lazily and cached, so a request that never sends mail
 * never opens a socket or reads credentials. Two delivery paths exist and
 * they are not interchangeable:
 *
 * - `send()` runs a transport now and logs exactly what it reported;
 * - `queue()` stores a {@see SendMailJob} and reports `queued`. It refuses to
 *   run when queued delivery is disabled, instead of silently sending
 *   synchronously or silently dropping the message.
 *
 * Whatever happens, logs contain no message content: no subject, no body and
 * no recipient addresses — only the transport, status, recipient count and
 * (when the provider returned one) a message id.
 */
final class MailManager implements MailerContract
{
    /** @var array<string, TransportContract> */
    private array $resolved = [];

    /**
     * @param array<string, mixed> $transports the `mail.transports` configuration
     * @param array<string, mixed> $defaultFrom the `mail.from` configuration
     * @param (Closure(): DispatcherContract)|null $dispatcher lazy queue dispatcher; not resolved unless queue() runs
     */
    public function __construct(
        private readonly array $transports = [],
        private readonly string $defaultTransport = 'log',
        private readonly array $defaultFrom = [],
        private readonly bool $queueEnabled = false,
        private readonly string $queueName = 'emails',
        private readonly int $queueDelay = 0,
        private readonly ?LoggerContract $logger = null,
        private readonly ?Closure $dispatcher = null,
    ) {
    }

    public function send(Message $message, ?string $transport = null): MailReceipt
    {
        $this->prepare($message);
        $name = $this->transportName($transport);

        try {
            $receipt = $this->transport($name)->send($message);
        } catch (MailException $failure) {
            $this->logFailure($name, $failure);

            throw $failure;
        } catch (Throwable $failure) {
            $wrapped = new TransportException(sprintf(
                'Transport [%s] failed: %s',
                $name,
                Sanitizer::error($failure->getMessage()),
            ), 0, $failure);

            $this->logFailure($name, $wrapped);

            throw $wrapped;
        }

        $this->logReceipt($receipt, $message);

        return $receipt;
    }

    public function queue(Message $message, int $delay = 0, ?string $transport = null): MailReceipt
    {
        $this->prepare($message);
        $name = $this->transportName($transport);

        if (!$this->queueEnabled) {
            throw new MailException(
                'Queued mail delivery is disabled; set MAIL_QUEUE=true and run a queue worker, or call send() for synchronous delivery.',
            );
        }

        if ($this->dispatcher === null) {
            throw new MailException(
                'Queued mail delivery needs a queue dispatcher, but none is bound; register the queue service provider.',
            );
        }

        $factory = $this->dispatcher;

        try {
            $queueId = $factory()->pushOn(
                $this->queueName,
                new SendMailJob($message, $name),
                max(0, $delay > 0 ? $delay : $this->queueDelay),
            );
        } catch (Throwable $failure) {
            throw new MailException(
                'The message could not be queued: '.Sanitizer::error($failure->getMessage()),
                0,
                $failure,
            );
        }

        $receipt = MailReceipt::queued($name, $queueId);
        $this->logReceipt($receipt, $message);

        return $receipt;
    }

    /**
     * Register a transport instance under a name, e.g. a provider API
     * adapter. An extended name wins over configuration.
     */
    public function extend(string $name, TransportContract $transport): void
    {
        $this->resolved[$name] = $transport;
    }

    public function transport(?string $name = null): TransportContract
    {
        $name = $this->transportName($name);

        if (isset($this->resolved[$name])) {
            return $this->resolved[$name];
        }

        $configuration = $this->transports[$name] ?? null;

        if (!is_array($configuration)) {
            throw new MailException(sprintf(
                'Mail transport [%s] is not configured; available: %s.',
                $name,
                $this->availableTransports(),
            ));
        }

        $driver = strtolower(trim((string) ($configuration['driver'] ?? $name)));

        return $this->resolved[$name] = match ($driver) {
            'smtp' => new SmtpTransport($name, $configuration),
            'log' => new LogTransport($name),
            'array' => new ArrayTransport($name),
            default => throw new MailException(sprintf(
                'Unsupported mail driver [%s] on transport [%s]; use [smtp], [log] or [array].',
                $driver,
                $name,
            )),
        };
    }

    /**
     * Apply the configured sender and validate the message before anything
     * touches a transport.
     */
    private function prepare(Message $message): void
    {
        if ($message->sender() === null) {
            $address = trim((string) ($this->defaultFrom['address'] ?? ''));

            if ($address === '') {
                throw new InvalidMessageException(
                    'No sender is configured: set MAIL_FROM_ADDRESS (mail.from.address) or call Message::from().',
                );
            }

            $name = trim((string) ($this->defaultFrom['name'] ?? ''));

            $message->from(Address::from($address, $name === '' ? null : $name));
        }

        $message->assertSendable();
    }

    private function transportName(?string $name): string
    {
        $name = trim($name ?? $this->defaultTransport);

        if ($name === '') {
            throw new MailException('No mail transport is configured; set MAIL_TRANSPORT or mail.default.');
        }

        return $name;
    }

    private function availableTransports(): string
    {
        $names = array_keys($this->transports);

        return $names === [] ? '(none configured)' : implode(', ', $names);
    }

    private function logReceipt(MailReceipt $receipt, Message $message): void
    {
        $this->logger?->log(
            $receipt->status->isFailure() ? 'error' : 'info',
            'mail.'.$receipt->status->value,
            [
                'transport' => $receipt->transport,
                'status' => $receipt->status->value,
                'recipients' => $message->recipientCount(),
                'message_id' => $receipt->messageId,
                'queue_id' => $receipt->queueId,
                'detail' => Sanitizer::error($receipt->detail),
            ],
        );
    }

    private function logFailure(string $transport, MailException $failure): void
    {
        $this->logger?->error('mail.failed', [
            'transport' => $transport,
            'status' => MailStatus::Failed->value,
            'exception' => $failure::class,
            'error' => Sanitizer::error($failure->getMessage()),
        ]);
    }
}
