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

    // Interface language: 'auto' (browser language), 'en' or 'ru'. Visitors can switch it with the EN | RU toggle.
    'lang'       => 'auto',

    // Admin panel login (username). Sign-in needs this username AND the password below.
    'admin_user' => 'admin',

    // Admin panel password. Generate a hash with:
    //   php -r "echo password_hash('your-password', PASSWORD_BCRYPT), PHP_EOL;"
    'admin_pass_hash' => '',

    // API token for POST /api.php. Generate with:
    //   php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"
    'api_token'  => '',

    // Allow anonymous shortening from the homepage form.
    // Set to false to make the service private (admin + API only).
    'public_form' => true,

    // Extra domains that serve short links (point them at this same folder), e.g. ['go.example.com', 'brand.link'].
    // A link can be bound to one of them when it is created; unbound links work on every domain.
    'domains' => [],

    // Read the visitor IP from X-Forwarded-For (right-most entry). Enable ONLY behind your own reverse proxy,
    // otherwise clients can spoof it and bypass the rate limit.
    'trust_proxy' => false,

    // Public form rate limit: max new links per IP within the window
    'rate_limit' => ['max' => 20, 'window_min' => 60],

    // Short code settings (length is clamped to 4–12)
    'code' => [
        'alphabet' => 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789',
        'length'   => 6,
    ],
];
