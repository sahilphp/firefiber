<?php

declare(strict_types=1);

namespace Sahil\Firefiber;

/**
 * Firefiber Communication Protocol - Phase 3.1
 *
 * Defines the versioned request/response schemas for secure browser-server
 * communication between the Firefiber browser runtime and PHP server.
 *
 * @see https://github.com/sahilphp/firefiber
 */
final class Protocol
{
    /** Protocol major version */
    public const int PROTOCOL_MAJOR = 3;

    /** Protocol minor version */
    public const int PROTOCOL_MINOR = 1;

    /** Full protocol version string */
    public const string PROTOCOL_VERSION = '3.1';

    /** Default request body size limit in bytes (64KB) */
    public const int DEFAULT_MAX_REQUEST_SIZE = 65536;

    /** Default maximum action argument size in bytes (32KB) */
    public const int DEFAULT_MAX_ARGUMENT_SIZE = 32768;

    /** Default maximum component state size in bytes (256KB) */
    public const int DEFAULT_MAX_STATE_SIZE = 262144;

    /** Default snapshot size limit in bytes (256KB) */
    public const int DEFAULT_MAX_SNAPSHOT_SIZE = 262144;

    /** Maximum allowed nested state depth */
    public const int MAX_NESTED_DEPTH = 20;

    /** Default request execution time limit in seconds */
    public const float DEFAULT_EXECUTION_TIME_LIMIT = 5.0;

    /** Cookie name for CSRF token */
    public const string CSRF_TOKEN_COOKIE_NAME = 'firefiber_csrf';

    /** Header name for CSRF token */
    public const string CSRF_TOKEN_HEADER_NAME = 'X-Csrf-Token';

    /** Header name for HMAC signature */
    public const string SIGNATURE_HEADER_NAME = 'X-Firefiber-Signature';

    /** Header name for target indicator */
    public const string TARGET_HEADER_NAME = 'X-Firefiber-Target';

    /** Request type: component registration/initialization */
    public const string TYPE_REGISTER = 'register';

    /** Request type: component action execution */
    public const string TYPE_ACTION = 'handleAction';

    /** Request type: state synchronization */
    public const string TYPE_STATE = 'state';

    /** Request type: component refresh */
    public const string TYPE_REFRESH = 'refresh';

    /** Request type: component destruction */
    public const string TYPE_DESTROY = 'destroy';

    /** Response status: success */
    public const string STATUS_SUCCESS = 'success';

    /** Response status: error */
    public const string STATUS_ERROR = 'error';

    /** Response status: redirect */
    public const string STATUS_REDIRECT = 'redirect';

    /** Error code: validation failure */
    public const int ERROR_VALIDATION = 1;

    /** Error code: unauthorized */
    public const int ERROR_UNAUTHORIZED = 2;

    /** Error code: invalid component */
    public const int ERROR_INVALID_COMPONENT = 3;

    /** Error code: invalid action */
    public const int ERROR_INVALID_ACTION = 4;

    /** Error code: expired snapshot */
    public const int ERROR_EXPIRED_SNAPSHOT = 5;

    /** Error code: modified snapshot */
    public const int ERROR_MODIFIED_SNAPSHOT = 6;

    /** Error code: invalid protocol version */
    public const int ERROR_INVALID_PROTOCOL = 7;

    /** Error code: missing required field */
    public const int ERROR_MISSING_FIELD = 8;

    /** Error code: unknown component */
    public const int ERROR_UNKNOWN_COMPONENT = 9;

    /** Error code: replay detection */
    public const int ERROR_REPLAY = 10;

    /** Error code: oversized request */
    public const int ERROR_OVERSIZED = 11;

    /** Error code: invalid content type */
    public const int ERROR_INVALID_CONTENT_TYPE = 12;

    /** Default JSON encoding options */
    public const int JSON_OPTIONS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
}
