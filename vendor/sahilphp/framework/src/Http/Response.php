<?php

declare(strict_types=1);

namespace Sahil\Http;

use InvalidArgumentException;
use JsonException;

/**
 * Immutable HTTP response: validated status, validated headers and a body.
 *
 * Construction rules (all enforced, never assumed):
 * - Status must be 100-599.
 * - Header names/values are validated by {@see HeaderBag}; CR/LF injection
 *   through this API is impossible and throws immediately.
 * - 1xx / 204 / 304 code statuses must have empty bodies (per RFC 7230).
 * - JSON responses encode strict payloads and always aim at the exact
 *   same serialization that was sent.
 *
 * HEAD handling: a response carries its full size metadata; when emitted
 * for a HEAD request the body is suppressed by the emitter but
 * `Content-Length` still reflects the would-be body, matching RFC 7231.
 */
final class Response
{
    private int $status;

    private HeaderBag $headers;

    private string $body;

    /**
     * @param array<string, scalar|array<int, scalar>|null> $headers
     */
    public function __construct(int $status = 200, array $headers = [], string $body = '')
    {
        if (!Status::isValid($status)) {
            throw new InvalidArgumentException(sprintf('Response status [%d] is outside the HTTP range 100-599.', $status));
        }

        if (!Status::allowsBody($status) && $body !== '') {
            throw new InvalidArgumentException(
                sprintf('Response status [%d] does not allow a body (%s).', $status, Status::reason($status)),
            );
        }

        $headerBag = new HeaderBag($headers);
        $headerBag->set('Content-Length', (string) strlen($body));

        $this->status = $status;
        $this->headers = $headerBag;
        $this->body = $body;
    }

    /**
     * Plain text response.
     */
    public static function text(string $body, int $status = 200): self
    {
        return new self($status, ['Content-Type' => 'text/plain; charset=utf-8'], $body);
    }

    /**
     * HTML response.
     *
     * The body is taken as already-rendered markup: the view layer escapes
     * every value it interpolates (see {@see \Sahil\View\Escaper}), so the
     * charset is declared here and the bytes are sent as produced.
     */
    public static function html(string $body, int $status = 200): self
    {
        return new self($status, ['Content-Type' => 'text/html; charset=utf-8'], $body);
    }

    /**
     * JSON response.
     *
     * @param mixed $data any JSON-encodable structure
     */
    public static function json(mixed $data, int $status = 200): self
    {
        $encoded = self::encodeJson($data);

        return new self($status, ['Content-Type' => 'application/json'], $encoded);
    }

    /**
     * Response with no body. Requires a status that must not carry a body
     * anyway (1xx, 204 or 304); 204 is the default.
     */
    public static function empty(int $status = 204): self
    {
        if (Status::allowsBody($status)) {
            throw new InvalidArgumentException(
                sprintf('empty() requires a bodyless status (1xx, 204 or 304); got [%d], which allows a body.', $status),
            );
        }

        return new self($status, [], '');
    }

    /**
     * Redirect. 301/302/303/307/308 only; the Location header is validated
     * the same way any header is (no CR/LF injection allowed).
     */
    public static function redirect(string $location, int $status = 302): self
    {
        if (!in_array($status, [301, 302, 303, 307, 308], true)) {
            throw new InvalidArgumentException(
                sprintf('Redirect status must be one of 301, 302, 303, 307, 308; got [%d].', $status),
            );
        }

        if ($location === '' || preg_match('/\r|\n|\x00|\x04/', $location) === 1) {
            throw new InvalidArgumentException('Redirect Location must be non-empty and header-injection free.');
        }

        return new self($status, ['Location' => $location], '');
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * Deep-clone the header bag so derived responses cannot mutate the
     * original's storage through the shared HeaderBag object.
     */
    public function __clone()
    {
        $this->headers = clone $this->headers;
    }

    public function body(): string
    {
        return $this->body;
    }

    /**
     * @deprecated Use body() instead
     * @return string
     */
    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * @return array<string, list<string>>
     */
    public function headers(): array
    {
        return $this->headers->all();
    }

    public function header(string $name): ?string
    {
        return $this->headers->line($name);
    }

    /**
     * Derive a new response with an added header (instance is immutable).
     *
     * @param scalar|array<int, scalar>|null $values
     */
    public function withHeader(string $name, mixed $values): self
    {
        $clone = clone $this;
        $clone->headers->set($name, $values);

        return $clone;
    }

    /**
     * Derive a new response with several headers added (immutable
     * derivation; keyed by header name).
     *
     * @param array<string, scalar|array<int, scalar>|null> $headers
     */
    public function withHeaders(array $headers): self
    {
        $clone = clone $this;

        foreach ($headers as $name => $values) {
            $clone->headers->set((string) $name, $values);
        }

        return $clone;
    }

    public function withStatus(int $status): self
    {
        if (!Status::isValid($status)) {
            throw new InvalidArgumentException(sprintf('Response status [%d] is outside the HTTP range 100-599.', $status));
        }

        $clone = clone $this;
        $clone->status = $status;

        return $clone;
    }

    /**
     * Derive a new response with a tracked request-identifier header.
     *
     * `X-Request-Id` is the correlation key for logs, metrics and tracing.
     * It is generated once per request by the HTTP kernel and attached by
     * the framework, so API consumers can cross-reference events.
     */
    public function withRequestId(string $id): self
    {
        return $this->withHeader('X-Request-Id', $id);
    }

    /**
     * Whether this response must be sent body-free (answer for the
     * request method / status semantics).
     */
    public function mustOmitBodyFor(Request $request): bool
    {
        return $request->method() === 'HEAD' || $this->status === 304 || $this->status < 200;
    }

    private static function encodeJson(mixed $data): string
    {
        try {
            return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                sprintf('Response payload could not be JSON-encoded: %s', $exception->getMessage()),
                0,
                $exception,
            );
        }
    }
}
