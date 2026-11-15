<?php

declare(strict_types=1);

namespace Sahil\Mail;

/**
 * One composed email: sender, recipients, subject, text and/or HTML body.
 *
 * The builder is fluent and mutable, mirroring the rest of the framework's
 * value builders ({@see \Sahil\Storage\StorageManager},
 * {@see \Sahil\Scheduler\Task}). Addresses, the subject and custom headers
 * are validated as they are set, so header injection is impossible; the
 * message as a whole is validated when it is handed to the mailer.
 *
 * `html()` is trusted markup — see {@see Html} for the escaping contract.
 * A message may carry only text, only HTML, or both; the mailer generates
 * the missing alternative.
 */
final class Message
{
    /** Headers owned by the message fields; setting them directly is refused. */
    private const RESERVED_HEADERS = [
        'bcc', 'cc', 'content-transfer-encoding', 'content-type', 'date',
        'from', 'mime-version', 'reply-to', 'return-path', 'sender',
        'subject', 'to',
    ];

    private ?Address $from = null;

    /** @var list<Address> */
    private array $to = [];

    /** @var list<Address> */
    private array $cc = [];

    /** @var list<Address> */
    private array $bcc = [];

    private ?Address $replyTo = null;

    private string $subject = '';

    private ?string $text = null;

    private ?string $html = null;

    /** @var array<string, string> */
    private array $headers = [];

    private function __construct()
    {
    }

    public static function make(): self
    {
        return new self();
    }

    /* ---------- addresses ---------- */

    public function from(Address|string $address, ?string $name = null): self
    {
        $this->from = self::toAddress($address, $name);

        return $this;
    }

    public function sender(): ?Address
    {
        return $this->from;
    }

    public function to(Address|string $address, ?string $name = null): self
    {
        $this->to[] = self::toAddress($address, $name);

        return $this;
    }

    public function cc(Address|string $address, ?string $name = null): self
    {
        $this->cc[] = self::toAddress($address, $name);

        return $this;
    }

    public function bcc(Address|string $address, ?string $name = null): self
    {
        $this->bcc[] = self::toAddress($address, $name);

        return $this;
    }

    public function replyTo(Address|string $address, ?string $name = null): self
    {
        $this->replyTo = self::toAddress($address, $name);

        return $this;
    }

    /** @return list<Address> */
    public function recipients(): array
    {
        return [...$this->to, ...$this->cc, ...$this->bcc];
    }

    /** @return list<Address> */
    public function toAddresses(): array
    {
        return $this->to;
    }

    /** @return list<Address> */
    public function ccAddresses(): array
    {
        return $this->cc;
    }

    /** @return list<Address> */
    public function bccAddresses(): array
    {
        return $this->bcc;
    }

    public function replyToAddress(): ?Address
    {
        return $this->replyTo;
    }

    public function hasRecipients(): bool
    {
        return $this->to !== [] || $this->cc !== [] || $this->bcc !== [];
    }

    /** Every mailbox that will be handed to the transport, cc and bcc included. */
    public function recipientCount(): int
    {
        return count($this->to) + count($this->cc) + count($this->bcc);
    }

    /* ---------- content ---------- */

    public function subject(string $subject): self
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $subject) === 1) {
            throw new InvalidMessageException('A subject cannot contain control characters or line breaks.');
        }

        $subject = trim($subject);

        if ($subject === '') {
            throw new InvalidMessageException('A subject cannot be empty.');
        }

        if (strlen($subject) > 998) {
            throw new InvalidMessageException('A subject cannot be longer than 998 bytes.');
        }

        $this->subject = $subject;

        return $this;
    }

    public function subjectLine(): string
    {
        return $this->subject;
    }

    public function text(string $text): self
    {
        $this->text = $text;

        return $this;
    }

    public function html(string $html): self
    {
        $this->html = $html;

        return $this;
    }

    public function textBody(): ?string
    {
        return $this->text;
    }

    public function htmlBody(): ?string
    {
        return $this->html;
    }

    /**
     * Add a custom header (e.g. `X-Entity-Ref-ID`).
     *
     * Header names are restricted to RFC 5322 tokens and values may not
     * contain line breaks; the headers that own message fields cannot be set
     * here, so `From`, `To`, `Subject`, ... have exactly one source.
     */
    public function header(string $name, string $value): self
    {
        if (preg_match('/^[A-Za-z0-9!#$%&\'*+\-.^_`|~]+$/', $name) !== 1) {
            throw new InvalidMessageException(sprintf('Invalid header name [%s].', $name));
        }

        if (in_array(strtolower($name), self::RESERVED_HEADERS, true)) {
            throw new InvalidMessageException(sprintf(
                'Header [%s] is managed by the message and cannot be set directly.',
                $name,
            ));
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidMessageException(sprintf(
                'Header [%s] cannot contain control characters or line breaks.',
                $name,
            ));
        }

        $this->headers[$name] = trim($value);

        return $this;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    /* ---------- validation ---------- */

    /**
     * Assert that this message can be handed to a transport.
     *
     * @throws InvalidMessageException when a sender, recipient, subject or
     *                                 body is missing
     */
    public function assertSendable(): void
    {
        if ($this->from === null) {
            throw new InvalidMessageException(
                'The message has no sender; set mail.from.address or call Message::from().',
            );
        }

        if (!$this->hasRecipients()) {
            throw new InvalidMessageException('The message has no recipients.');
        }

        if ($this->subject === '') {
            throw new InvalidMessageException('The message has no subject.');
        }

        if (trim((string) $this->text) === '' && trim((string) $this->html) === '') {
            throw new InvalidMessageException('The message has no text or HTML body.');
        }
    }

    private static function toAddress(Address|string $address, ?string $name): Address
    {
        if ($address instanceof Address) {
            return $address;
        }

        // `Name <mailbox>` is accepted for convenience; an explicit name wins
        // over one embedded in the string.
        if (str_contains($address, '<')) {
            $parsed = Address::parse($address);

            return Address::from(
                $parsed->email,
                $name ?? ($parsed->name === '' ? null : $parsed->name),
            );
        }

        return Address::from($address, $name);
    }
}
