<?php

declare(strict_types=1);

namespace Sahil\Mail\Transports;

use Sahil\Contracts\Mail\TransportContract;
use Sahil\Mail\Address;
use Sahil\Mail\MailException;
use Sahil\Mail\MailReceipt;
use Sahil\Mail\Message;
use Sahil\Mail\MessageRenderer;
use Sahil\Mail\Sanitizer;
use Sahil\Mail\TransportException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address as SymfonyAddress;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * SMTP transport, backed by symfony/mailer.
 *
 * The library is used rather than a hand-written socket client because SMTP
 * correctness is not a small problem: MIME encoding (RFC 2045/2047), header
 * folding (RFC 5322), ESMTP capability negotiation, STARTTLS vs. implicit TLS,
 * SASL PLAIN/LOGIN/XOAUTH2, and connection reuse are all handled there and
 * actively maintained. Everything application-facing stays behind
 * {@see TransportContract}, so the dependency is replaceable.
 *
 * Configuration (`mail.transports.<name>`):
 *
 *   host        SMTP hostname
 *   port        TCP port (587 for STARTTLS, 465 for implicit TLS)
 *   security    `tls` (STARTTLS, required), `ssl` (implicit TLS) or `none`
 *   auth.username / auth.password   set both or neither
 *   timeout     socket timeout in seconds (0 = PHP default)
 *   local_domain EHLO domain (defaults to the transport's choice)
 *
 * A successful send returns `accepted`: the SMTP server answered `250` after
 * the message body, which means it took responsibility for the message. It
 * does NOT mean the message reached the mailbox.
 */
final class SmtpTransport implements TransportContract
{
    private ?TransportInterface $mailer = null;

    /**
     * @param array<string, mixed> $configuration the transport's configuration block
     * @param TransportInterface|null $mailer pre-built library transport (tests/advanced wiring)
     */
    public function __construct(
        private readonly string $name,
        private readonly array $configuration,
        ?TransportInterface $mailer = null,
    ) {
        $this->mailer = $mailer;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function send(Message $message): MailReceipt
    {
        $message->assertSendable();

        try {
            $sent = $this->mailer()->send($this->toEmail($message));
        } catch (Throwable $failure) {
            throw new TransportException(sprintf(
                'SMTP transport [%s] failed: %s',
                $this->name,
                Sanitizer::error($failure->getMessage(), $this->credentials()),
            ), 0, $failure);
        }

        if ($sent === null) {
            throw new TransportException(sprintf(
                'SMTP transport [%s] refused the message (an event listener rejected it).',
                $this->name,
            ));
        }

        $messageId = $sent->getMessageId();

        return MailReceipt::accepted($this->name, $messageId === '' ? null : $messageId);
    }

    private function mailer(): TransportInterface
    {
        return $this->mailer ??= $this->buildMailer();
    }

    private function buildMailer(): TransportInterface
    {
        $host = trim((string) ($this->configuration['host'] ?? ''));
        $port = (int) ($this->configuration['port'] ?? 0);
        $security = strtolower(trim((string) ($this->configuration['security'] ?? 'tls')));

        if ($host === '') {
            throw new MailException(sprintf('SMTP transport [%s] has no host configured.', $this->name));
        }

        if (!in_array($security, ['tls', 'ssl', 'none'], true)) {
            throw new MailException(sprintf(
                'SMTP transport [%s] has an unsupported security mode [%s]; use [tls], [ssl] or [none].',
                $this->name,
                $security,
            ));
        }

        // Implicit TLS (SMTPS) is selected by the third constructor argument.
        $mailer = new EsmtpTransport($host, $port, $security === 'ssl');

        // symfony/mailer >= 7.1 can require STARTTLS explicitly; the 6.4 LTS
        // line (the only one available on PHP 8.1) negotiates STARTTLS
        // opportunistically and exposes no switch for it.
        // @phpstan-ignore-next-line the method only exists on symfony/mailer >= 7.1
        if (method_exists($mailer, 'setAutoTls')) {
            $mailer->setAutoTls($security === 'tls');
            $mailer->setRequireTls($security === 'tls');
        }

        $auth = is_array($this->configuration['auth'] ?? null) ? $this->configuration['auth'] : [];
        $username = trim((string) ($auth['username'] ?? ''));
        $password = (string) ($auth['password'] ?? '');

        if ($username !== '' && $password !== '') {
            $mailer->setUsername($username);
            $mailer->setPassword($password);
        } elseif ($username !== '' || $password !== '') {
            throw new MailException(sprintf(
                'SMTP transport [%s] needs both auth.username and auth.password, or neither.',
                $this->name,
            ));
        }

        $timeout = (float) ($this->configuration['timeout'] ?? 0);
        $stream = $mailer->getStream();

        if ($timeout > 0 && $stream instanceof SocketStream) {
            $stream->setTimeout($timeout);
        }

        $localDomain = trim((string) ($this->configuration['local_domain'] ?? ''));

        if ($localDomain !== '') {
            $mailer->setLocalDomain($localDomain);
        }

        return $mailer;
    }

    /**
     * Map the framework message onto the library's MIME message.
     *
     * Both body parts are always present, so clients that prefer plain text
     * and clients that render HTML each get a complete alternative.
     */
    private function toEmail(Message $message): Email
    {
        $email = new Email();

        if ($message->sender() !== null) {
            $email->from(self::symfonyAddress($message->sender()));
        }

        foreach ($message->toAddresses() as $address) {
            $email->addTo(self::symfonyAddress($address));
        }

        foreach ($message->ccAddresses() as $address) {
            $email->addCc(self::symfonyAddress($address));
        }

        foreach ($message->bccAddresses() as $address) {
            $email->addBcc(self::symfonyAddress($address));
        }

        if ($message->replyToAddress() !== null) {
            $email->replyTo(self::symfonyAddress($message->replyToAddress()));
        }

        $email->subject($message->subjectLine());
        $email->text(MessageRenderer::text($message));
        $email->html(MessageRenderer::html($message));

        foreach ($message->headers() as $name => $value) {
            $email->getHeaders()->addTextHeader($name, $value);
        }

        return $email;
    }

    /**
     * Configured credentials, so error text (which some libraries populate
     * with the username) can be scrubbed before it reaches a log.
     *
     * @return list<string>
     */
    private function credentials(): array
    {
        $auth = is_array($this->configuration['auth'] ?? null) ? $this->configuration['auth'] : [];

        return array_values(array_filter(
            [(string) ($auth['username'] ?? ''), (string) ($auth['password'] ?? '')],
            static fn (string $value): bool => $value !== '',
        ));
    }

    private static function symfonyAddress(Address $address): SymfonyAddress
    {
        return new SymfonyAddress($address->email, $address->name);
    }
}
