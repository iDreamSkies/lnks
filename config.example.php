<?php
/**
 * lnks — configuration
 * Copy this file to config.php and adjust values.
 */
return [
    'app_name'   => 'lnks',
    'base_url'   => '',                 // e.g. 'https://lnks.example.com' — leave empty to auto-detect
    'timezone'   => 'UTC',
    'db_path'    => __DIR__ . '/storage/lnks.sqlite',

    // Admin panel password. Generate a hash with:
    //   php -r "echo password_hash('your-password', PASSWORD_BCRYPT), PHP_EOL;"
    'admin_pass_hash' => '',

    // API token for POST /api.php. Generate with:
    //   php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"
    'api_token'  => '',

    // Allow anonymous shortening from the homepage form.
    // Set to false to make the service private (admin + API only).
    'public_form' => true,

    // Short code settings
    'code' => [
        'alphabet' => 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789',
        'length'   => 6,
    ],
];
