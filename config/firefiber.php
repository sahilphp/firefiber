<?php
declare(strict_types=1);

return [
    'action_path' => getenv('FIREFIBER_ACTION_PATH') ?: '/firefiber',
    'upload_path' => getenv('FIREFIBER_UPLOAD_PATH') ?: '/firefiber/uploads',
    'hmac_key' => getenv('FIREFIBER_HMAC_KEY') ?: null,
    'snapshot_expiration_seconds' => (int) (getenv('FIREFIBER_SNAPSHOT_EXPIRATION') ?: 300),
    'state_ttl_seconds' => (int) (getenv('FIREFIBER_STATE_TTL') ?: 0),
    'assets' => [
        // Public URL prefix for package-managed browser assets. The provider
        // serves these from the installed Composer package; nothing is copied
        // into the application's public directory.
        'public_prefix' => getenv('FIREFIBER_ASSET_PREFIX') ?: '/firefiber/assets',
        // null chooses runtime.min.js in production and the readable
        // runtime.js source elsewhere.
        'development' => null,
    ],
];
