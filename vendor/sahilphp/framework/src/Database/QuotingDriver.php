<?php

declare(strict_types=1);

namespace Sahil\Database;

use InvalidArgumentException;

/**
 * Shared identifier-quoting logic: validate first, then wrap in the
 * dialect's quote characters, doubling embedded quotes.
 */
abstract class QuotingDriver implements DriverContract
{
    /** @return list<string> */
    abstract protected function quoteMarks(): array;

    public function quoteIdentifier(string $identifier): string
    {
        $segments = Identifier::segments($identifier);
        $marks = $this->quoteMarks();
        $quoted = [];

        foreach ($segments as $segment) {
            $quoted[] = $marks[0].str_replace($marks[0], $marks[0].$marks[0], $segment).$marks[1];
        }

        return implode('.', $quoted);
    }

    /**
     * Common numeric/port checks shared by networked drivers.
     *
     * @param array<string, mixed> $configuration
     *
     * @return array<string, string>
     */
    protected function validatedEndpoint(array $configuration): array
    {
        $host = $configuration['host'] ?? null;

        if (!is_string($host) || $host === '') {
            throw new InvalidArgumentException('Database configuration requires a host.');
        }

        $port = $configuration['port'] ?? null;

        if ($port !== null && (!is_int($port) || $port < 1 || $port > 65535)) {
            throw new InvalidArgumentException(sprintf('Database port [%s] is invalid.', var_export($port, true)));
        }

        return [
            'host' => $host,
            'port' => $port === null ? '' : (string) $port,
        ];
    }
}
