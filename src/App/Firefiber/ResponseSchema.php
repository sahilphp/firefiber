<?php

declare(strict_types=1);

namespace Sahil\Firefiber;

/** @internal */
final class ResponseSchema
{
    /** Required fields for all successful responses */
    public static array $successRequired = ['protocolVersion', 'requestId', 'success', 'status'];

    /** Required fields for error responses */
    public static array $errorRequired = ['protocolVersion', 'requestId', 'status', 'error'];

    /** Required fields for validation failure responses */
    public static array $validationRequired = ['protocolVersion', 'requestId', 'status', 'error', 'validationErrors'];

    /** @return list<string> */
    public static function getSuccessRequired(): array
    {
        return ['protocolVersion', 'requestId', 'success', 'status'];
    }

    /** @return list<string> */
    public static function getErrorRequired(): array
    {
        return ['protocolVersion', 'requestId', 'status', 'error'];
    }

    /** @return list<string> */
    public static function getValidationRequired(): array
    {
        return ['protocolVersion', 'requestId', 'status', 'error', 'validationErrors'];
    }

    /** @return list<string> */
    public static function getOptionalFields(): array
    {
        return ['updatedState', 'changedProperties', 'redirect', 'navigationMetadata', 'snapshot', 'errorCode', 'timestamp'];
    }
}