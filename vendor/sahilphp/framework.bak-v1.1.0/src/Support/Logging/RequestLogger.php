<?php

declare(strict_types=1);

namespace Sahil\Support\Logging;

use Sahil\Http\Request;
use Sahil\Support\Redactor;

/**
 * One-shot structured logger for a handled request.
 *
 * Emits one JSON object:
 *
 *   {
 *     "timestamp": "2026-10-27T12:00:00+00:00",
 *     "level": "info",
 *     "method": "GET",
 *     "uri": "/api/users",
 *     "path": "/api/users",
 *     "status": 200,
 *     "content_type": "application/json",
 *     "request_id": "a1b2c3d4e5f6...",
 *     "ip": "203.0.113.7",
 *     "user_agent": "SahilTest/1.0",
 *     "context": { "...": "..." }
 *   }
 *
 * The `request_id` is the same correlation key the framework attaches to
 * the response as `X-Request-Id`, so request logs can be joined to the
 * response layer.
 *
 * Context values pass through {@see Redactor::flattenSafely()} before the
 * event is written, so passwords, tokens, CSRF values and API keys never
 * reach the log stream.
 */
final class RequestLogger
{
    public function __construct(
        private readonly LoggerContract $logger,
        private readonly ?Request $request = null,
        private readonly ?string $requestId = null,
    ) {
    }

    public static function forRequest(LoggerContract $logger, Request $request): self
    {
        return new self($logger, $request);
    }

    /**
     * Record one event. The level is derived from the status code so the
     * same code path works for success and error paths.
     */
    public function record(string $message, int $status = 200, array $context = []): void
    {
        $event = $this->structured($context, $status);

        if ($this->logger instanceof StructuredLoggerContract) {
            $event['message'] = $message;
            $this->logger->logEvent($event);

            return;
        }

        $this->logger->log($this->levelFor($status), $message, $event);
    }

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->record($message, 200, $context);
    }

    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->record($message, 400, $context);
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->record($message, 500, $context);
    }

    private function structured(array $context, int $status): array
    {
        return [
            'timestamp' => gmdate('c'),
            'level' => $this->levelFor($status),
            'method' => $this->request ? $this->request->method() : 'unknown',
            'uri' => $this->request ? $this->request->uri() : '-',
            'path' => $this->request ? $this->request->path() : '-',
            'status' => $status,
            'content_type' => $this->request ? $this->request->contentType() : null,
            'request_id' => $this->requestId,
            'ip' => $this->request ? $this->request->clientIp() : null,
            'user_agent' => $this->request ? $this->request->header('User-Agent') : null,
            'context' => Redactor::flattenSafely($context),
        ];
    }

    private function levelFor(int $status): string
    {
        return $status >= 500 ? 'error' : ($status >= 400 ? 'warning' : 'info');
    }
}
