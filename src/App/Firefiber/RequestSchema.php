<?php

declare(strict_types=1);

namespace Sahil\Firefiber;

/** @internal */
final class RequestSchema
{
    /** @return list<string> */
    public static function getRequiredFields(): array
    {
        return ['protocolVersion', 'requestId', 'componentId', 'csrfToken', 'signature'];
    }

    /** @return list<string> */
    public static function getAllowedFields(string $type): array
    {
        return match ($type) {
            Protocol::TYPE_REGISTER => ['protocolVersion', 'requestId', 'componentId', 'componentName', 'action', 'arguments', 'state', 'csrfToken', 'signature', 'timestamp'],
            Protocol::TYPE_ACTION => ['protocolVersion', 'requestId', 'componentId', 'action', 'payload', 'csrfToken', 'signature', 'timestamp'],
            Protocol::TYPE_STATE => ['protocolVersion', 'requestId', 'componentId', 'state', 'csrfToken', 'signature', 'timestamp'],
            Protocol::TYPE_REFRESH => ['protocolVersion', 'requestId', 'componentId', 'action', 'state', 'csrfToken', 'signature', 'timestamp'],
            Protocol::TYPE_DESTROY => ['protocolVersion', 'requestId', 'componentId', 'csrfToken', 'signature', 'timestamp'],
            default => [],
        };
    }
}
