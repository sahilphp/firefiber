<?php

declare(strict_types=1);

namespace Sahil\Mail\Transports;

use Sahil\Contracts\Mail\TransportContract;
use Sahil\Mail\MailReceipt;
use Sahil\Mail\Message;

/**
 * In-memory transport: keeps every message so tests and local tooling can
 * assert exactly what would have been sent.
 *
 * Nothing leaves the process and nothing is written to disk. Like the log
 * transport, its receipt never claims transmission.
 */
final class ArrayTransport implements TransportContract
{
    /** @var list<Message> */
    private array $messages = [];

    public function __construct(private readonly string $name = 'array')
    {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function send(Message $message): MailReceipt
    {
        $message->assertSendable();

        $this->messages[] = clone $message;

        return MailReceipt::accepted($this->name, sprintf('<%s@sahilphp.local>', bin2hex(random_bytes(12))));
    }

    /**
     * Messages captured so far, in send order.
     *
     * @return list<Message>
     */
    public function messages(): array
    {
        return $this->messages;
    }

    public function last(): ?Message
    {
        return $this->messages === [] ? null : $this->messages[array_key_last($this->messages)];
    }

    public function flush(): void
    {
        $this->messages = [];
    }
}
