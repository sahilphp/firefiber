<?php
declare(strict_types=1);
return [
    'firefiber' => [
        'hmac_key' => getenv('FIREFIBER_HMAC_KEY'),
        'csrf_token_name' => 'firefiber_csrf',
        'session_name' => 'sahilphp_session',
        'cookie_lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => false,
        'httponly' => true,
        'namespace' => 'data-firefiber',
        'attributes' => [
            'action' => 'data-firefiber-action',
            'submit' => 'data-firefiber-submit',
            'navigate' => 'data-firefiber-navigate',
        ],
        'headers' => [
            'xsrf_token' => 'X-Csrf-Token',
            'signature' => 'X-Firefiber-Signature',
            'target' => 'X-Firefiber-Target',
        ],
        'protocol_version' => '1.0',
        'enable_logging' => false,
        'assets' => [
            // Public URL prefix for package-managed browser assets. The
            // provider serves these from the installed Composer package;
            // nothing is copied into the application's public directory.
            'public_prefix' => '/firefiber/assets',
            // null chooses runtime.min.js in production and the readable
            // runtime.js source elsewhere. Override with true/false only if
            // an application deliberately wants the other mode.
            'development' => null,
        ],
    ],
];