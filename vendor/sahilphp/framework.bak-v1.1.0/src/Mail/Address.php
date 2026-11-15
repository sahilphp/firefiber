<?php

declare(strict_types=1);

namespace Sahil\Mail;

use Stringable;

/**
 * A single validated mailbox: an address plus an optional display name.
 *
 * Validation happens at construction, so an address object is always safe to
 * hand to a transport. Addresses are intentionally ASCII-only: SMTPUTF8 is
 * not negotiated by the bundled transport, and refusing an address up front is
 * better than a server-side `5xx` later.
 */
final class Address implements Stringable
{
    public const MAX_EMAIL_LENGTH = 254;

    public const MAX_NAME_LENGTH = 200;

    private function __construct(
        public readonly string $email,
        public readonly string $name = '',
    ) {
    }

    /**
     * Build an address from an email and an optional display name.
     *
     * @throws InvalidMessageException for a malformed address, control
     *                                 characters or CR/LF (header injection)
     */
    public static function from(string $email, ?string $name = null): self
    {
        $email = trim($email);

        if ($email === '') {
            throw new InvalidMessageException('An email address cannot be empty.');
        }

        if (strlen($email) > self::MAX_EMAIL_LENGTH) {
            throw new InvalidMessageException(sprintf(
                'The email address is longer than %d characters.',
                self::MAX_EMAIL_LENGTH,
            ));
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $email) === 1) {
            throw new InvalidMessageException('An email address cannot contain control characters or line breaks.');
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidMessageException(sprintf('Invalid email address [%s].', $email));
        }

        return new self($email, self::validName($name));
    }

    /**
     * Parse `Name <address>` or a bare address.
     */
    public static function parse(string $value): self
    {
        $value = trim($value);

        if ($value === '') {
            throw new InvalidMessageException('An email address cannot be empty.');
        }

        if (preg_match('/^(?:"?([^"<>]*?)"?\s*)?<([^<>]+)>$/', $value, $matches) === 1) {
            $name = trim($matches[1]);

            return self::from($matches[2], $name === '' ? null : $name);
        }

        return self::from($value);
    }

    public function domain(): string
    {
        $position = strrpos($this->email, '@');

        return $position === false ? '' : substr($this->email, $position + 1);
    }

    /**
     * `Name <address>` or the bare address when there is no display name.
     */
    public function display(): string
    {
        if ($this->name === '') {
            return $this->email;
        }

        $name = str_replace(['\\', '"'], ['\\\\', '\\"'], $this->name);

        return sprintf('"%s" <%s>', $name, $this->email);
    }

    public function __toString(): string
    {
        return $this->display();
    }

    private static function validName(?string $name): string
    {
        if ($name === null) {
            return '';
        }

        $name = trim($name);

        if ($name === '') {
            return '';
        }

        $length = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);

        if ($length > self::MAX_NAME_LENGTH) {
            throw new InvalidMessageException(sprintf(
                'A display name cannot be longer than %d characters.',
                self::MAX_NAME_LENGTH,
            ));
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            throw new InvalidMessageException('A display name cannot contain control characters or line breaks.');
        }

        return $name;
    }
}
