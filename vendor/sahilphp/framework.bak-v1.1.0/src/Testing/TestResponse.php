<?php

declare(strict_types=1);

namespace Sahil\Testing;

use PHPUnit\Framework\Assert;
use Sahil\Http\Response;

/**
 * Fluent assertions over one simulated HTTP response.
 *
 * Every failure includes the request line, status, headers and a truncated
 * body, so a broken route is diagnosable from the test output alone. Assertions
 * never re-send the request; they only inspect the stored response.
 */
final class TestResponse
{
    public function __construct(
        public readonly string $method,
        public readonly string $uri,
        public readonly int $status,
        public readonly Response $response,
        /** @var array<string, string> */
        public readonly array $headers = [],
        public readonly string $body = '',
    ) {
    }

    public static function fromResponse(string $method, string $uri, Response $response): self
    {
        $headers = [];

        foreach ($response->headers() as $name => $values) {
            $headers[(string) $name] = implode(', ', $values);
        }

        return new self($method, $uri, $response->status(), $response, $headers, $response->body());
    }

    public function assertStatus(int $expected): self
    {
        Assert::assertSame($expected, $this->status, sprintf(
            "Expected status %d, got %d for %s.\n%s",
            $expected,
            $this->status,
            $this->requestLine(),
            $this->diagnostics(),
        ));

        return $this;
    }

    public function assertOk(): self
    {
        return $this->assertStatus(200);
    }

    public function assertCreated(): self
    {
        return $this->assertStatus(201);
    }

    public function assertNoContent(): self
    {
        return $this->assertStatus(204);
    }

    public function assertNotFound(): self
    {
        return $this->assertStatus(404);
    }

    public function assertForbidden(): self
    {
        return $this->assertStatus(403);
    }

    public function assertUnauthorized(): self
    {
        return $this->assertStatus(401);
    }

    public function assertUnprocessable(): self
    {
        return $this->assertStatus(422);
    }

    public function assertRedirect(?string $location = null): self
    {
        Assert::assertTrue($this->status >= 300 && $this->status <= 399, sprintf(
            "Expected a redirect status, got %d for %s.\n%s",
            $this->status,
            $this->requestLine(),
            $this->diagnostics(),
        ));

        if ($location !== null) {
            $this->assertHeader('Location', $location);
        }

        return $this;
    }

    public function assertHeader(string $name, ?string $value = null): self
    {
        $actual = $this->header($name);

        Assert::assertNotNull($actual, sprintf(
            "Expected header [%s] on %s, but it is missing.\n%s",
            $name,
            $this->requestLine(),
            $this->diagnostics(),
        ));

        if ($value !== null) {
            Assert::assertSame($value, $actual, sprintf(
                "Expected header [%s] to be [%s], got [%s] for %s.\n%s",
                $name,
                $value,
                $actual,
                $this->requestLine(),
                $this->diagnostics(),
            ));
        }

        return $this;
    }

    public function assertHeaderMissing(string $name): self
    {
        Assert::assertNull($this->header($name), sprintf(
            "Expected header [%s] to be absent on %s.\n%s",
            $name,
            $this->requestLine(),
            $this->diagnostics(),
        ));

        return $this;
    }

    public function assertSee(string $needle): self
    {
        Assert::assertTrue(str_contains($this->body, $needle), sprintf(
            "Expected response body for %s to contain [%s].\n%s",
            $this->requestLine(),
            $needle,
            $this->diagnostics(),
        ));

        return $this;
    }

    public function assertDontSee(string $needle): self
    {
        Assert::assertFalse(str_contains($this->body, $needle), sprintf(
            "Expected response body for %s not to contain [%s].\n%s",
            $this->requestLine(),
            $needle,
            $this->diagnostics(),
        ));

        return $this;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function json(): array
    {
        $decoded = json_decode($this->body, true);

        if (!is_array($decoded)) {
            Assert::fail(sprintf(
                "Expected a JSON object or array from %s, but the body is not valid JSON (json error: %s).\n%s",
                $this->requestLine(),
                json_last_error_msg(),
                $this->diagnostics(),
            ));
        }

        return $decoded;
    }

    /**
     * @param array<array-key, mixed> $expected
     */
    public function assertJson(array $expected): self
    {
        $actual = $this->json();

        foreach ($expected as $key => $value) {
            Assert::assertArrayHasKey($key, $actual, sprintf(
                "Expected JSON key [%s] in response from %s.\nActual JSON: %s",
                (string) $key,
                $this->requestLine(),
                $this->jsonExcerpt($actual),
            ));

            Assert::assertSame($value, $actual[$key], sprintf(
                'JSON key [%s] did not match for %s.',
                (string) $key,
                $this->requestLine(),
            ));
        }

        return $this;
    }

    public function assertJsonPath(string $path, mixed $expected): self
    {
        $found = false;
        $actual = $this->jsonPath($path, $found);

        Assert::assertTrue($found, sprintf(
            "Expected JSON path [%s] in response from %s, but it is missing.\nActual JSON: %s",
            $path,
            $this->requestLine(),
            $this->jsonExcerpt($this->json()),
        ));

        Assert::assertSame($expected, $actual, sprintf(
            'JSON path [%s] did not match for %s.',
            $path,
            $this->requestLine(),
        ));

        return $this;
    }

    public function assertJsonCount(int $count, ?string $path = null): self
    {
        $json = $this->json();

        if ($path !== null) {
            $found = false;
            $json = $this->jsonPath($path, $found);

            Assert::assertTrue($found, sprintf(
                "Expected JSON path [%s] in response from %s, but it is missing.\nActual JSON: %s",
                $path,
                $this->requestLine(),
                $this->jsonExcerpt($this->json()),
            ));
        }

        Assert::assertIsArray($json, sprintf(
            'Expected JSON at [%s] to be a list or object for %s.',
            $path ?? '(root)',
            $this->requestLine(),
        ));

        Assert::assertCount($count, $json, sprintf(
            'Expected %d JSON entries at [%s] for %s.',
            $count,
            $path ?? '(root)',
            $this->requestLine(),
        ));

        return $this;
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $header => $value) {
            if (strcasecmp($header, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    public function requestLine(): string
    {
        return sprintf('%s %s', $this->method, $this->uri);
    }

    /**
     * A compact failure report: status, headers and at most 1200 body bytes.
     */
    public function diagnostics(int $limit = 1200): string
    {
        $headers = $this->headers === []
            ? '  (none)'
            : implode("\n", array_map(
                static fn (string $name, string $value): string => '  '.$name.': '.$value,
                array_keys($this->headers),
                array_values($this->headers),
            ));

        $body = $this->body === '' ? '(empty)' : self::truncate($this->body, $limit);

        return "Status: {$this->status}\nHeaders:\n{$headers}\nBody:\n{$body}";
    }

    private function jsonPath(string $path, bool &$found): mixed
    {
        $current = $this->json();
        $found = true;

        foreach (explode('.', $path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                $found = false;

                return null;
            }

            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * @param array<array-key, mixed> $json
     */
    private function jsonExcerpt(array $json): string
    {
        $encoded = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? '(unencodable JSON)' : self::truncate($encoded, 1200);
    }

    private static function truncate(string $value, int $limit): string
    {
        if (strlen($value) <= $limit) {
            return $value;
        }

        return substr($value, 0, $limit).sprintf("\n… (%d more bytes)", strlen($value) - $limit);
    }
}
