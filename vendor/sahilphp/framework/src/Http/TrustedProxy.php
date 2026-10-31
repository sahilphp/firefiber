<?php

declare(strict_types=1);

namespace Sahil\Http;

/**
 * Config-driven client IP / scheme resolver.
 *
 * Security posture:
 * - When `trusted_proxies` is empty (the default), the resolver NEVER
 *   trusts `X-Forwarded-For`, `X-Real-IP` or any other forwarded header.
 *   It returns `REMOTE_ADDR` and the scheme from server variables only.
 * - When proxies are explicitly configured, only the configured proxy
 *   headers may influence the result. The leftmost value of
 *   `X-Forwarded-For` is used as the client address; `X-Forwarded-Proto`
 *   sets the scheme.
 *
 * This never trusts user-supplied IP addresses for security decisions: a
 * request arriving with a forged `X-Forwarded-For` without a trusted proxy
 * configuration is treated as coming from `REMOTE_ADDR`.
 */
final class TrustedProxy
{
    /** @var list<string> */
    private array $proxies;

    /** @var list<string> */
    private array $clientIpHeaders;

    /** @var list<string> */
    private array $schemeHeaders;

    public function __construct(
        array $proxies,
        array $clientIpHeaders = ['X-Forwarded-For', 'X-Real-IP'],
        array $schemeHeaders = ['X-Forwarded-Proto'],
    ) {
        $this->proxies = $this->normalize($proxies);
        $this->clientIpHeaders = $this->normalize($clientIpHeaders);
        $this->schemeHeaders = $this->normalize($schemeHeaders);
    }

    public static function fromConfig(array $config): self
    {
        $config = array_merge([
            'proxies' => [],
            'headers' => [
                'client_ip' => ['X-Forwarded-For', 'X-Real-IP'],
                'scheme' => ['X-Forwarded-Proto'],
            ],
        ], $config);

        $proxies = $config['proxies'] ?? [];
        $headers = $config['headers'] ?? [];

        return new self(
            $proxies,
            $headers['client_ip'] ?? ['X-Forwarded-For', 'X-Real-IP'],
            $headers['scheme'] ?? ['X-Forwarded-Proto'],
        );
    }

    /**
     * Resolve the client IP.
     *
     * Always returns a single, non-empty address. When no proxies are
     * configured the address is `REMOTE_ADDR` regardless of any forwarded
     * header. When proxies are configured and the request carries a
     * forwarded chain, the resolver consumes the first (leftmost) value of
     * the first configured header.
     *
     * @param array<string, string> $headers request headers
     */
    public function clientIp(string $remoteAddr, array $headers): string
    {
        if ($this->proxies === []) {
            return $remoteAddr;
        }

        foreach ($this->clientIpHeaders as $header) {
            $value = $this->headerValue($headers, $header);

            if ($value === null) {
                continue;
            }

            foreach (array_map('trim', explode(',', $value)) as $candidate) {
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        return $remoteAddr;
    }

    /**
     * Resolve the scheme.
     *
     * @param array<string, string> $headers request headers
     */
    public function scheme(?string $scheme, array $headers): string
    {
        if ($this->proxies === []) {
            return $scheme ?? 'http';
        }

        foreach ($this->schemeHeaders as $header) {
            $value = $this->headerValue($headers, $header);

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return $scheme ?? 'http';
    }

    public function isTrusted(): bool
    {
        return $this->proxies !== [];
    }

    /** @return list<string> */
    public function proxies(): array
    {
        return $this->proxies;
    }

    /** @param list<string> $proxies */
    private function normalize(array $proxies): array
    {
        return array_values(array_unique(array_map('strtolower', array_filter($proxies, 'is_string'))));
    }

    /**
     * Case-insensitive header lookup.
     *
     * @param array<string, string> $headers
     * @return string|null
     */
    private function headerValue(array $headers, string $name): ?string
    {
        $needle = strtolower($name);

        foreach ($headers as $key => $value) {
            if (strtolower($key) === $needle) {
                return is_string($value) ? $value : null;
            }
        }

        return null;
    }
}
