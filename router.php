<?php
/**
 * lnks — dev router for the PHP built-in server.
 * Usage: php -S localhost:8080 router.php
 * Not needed in production (Apache/Nginx handle routing).
 */
$path = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// Never serve the database, config or internal files (the .htaccess rules do not apply here)
if (preg_match('~^/(storage/|lang/|tests/|scripts/|\.git|config(\.example)?\.php$|schema\.sql$|bootstrap\.php$|layout\.php$|i18n\.php$|csv\.php$|ua\.php$|geo\.php$|router\.php$|README(\.ru)?\.md$)~', $path)
    || str_contains($path, '..')) {
    http_response_code(403);
    exit('Forbidden');
}

if ($path !== '/' && is_file(__DIR__ . $path)) {
    return false; // serve the real file (admin.php, api.php, public/*)
}
require __DIR__ . '/index.php';
