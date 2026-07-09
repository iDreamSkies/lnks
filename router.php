<?php
/**
 * lnks — dev router for the PHP built-in server.
 * Usage: php -S localhost:8080 router.php
 * Not needed in production (Apache/Nginx handle routing).
 */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/' && is_file(__DIR__ . $path)) {
    return false; // serve the real file (admin.php, api.php, public/*)
}
require __DIR__ . '/index.php';
