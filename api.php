<?php
/**
 * lnks — REST API
 *
 * POST /api.php
 *   Headers: Authorization: Bearer <api_token>
 *   Body (JSON): { "url": "https://example.com/long" }
 *   or form-encoded: url=https://example.com/long
 *
 * Response 201: { "ok": true, "code": "aB3xYz", "short_url": "https://.../aB3xYz" }
 */
require __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondJson(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$token = cfg()['api_token'];
if ($token === '') {
    respondJson(['ok' => false, 'error' => 'API is disabled (api_token not configured)'], 503);
}

$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!preg_match('~^Bearer\s+(.+)$~i', $auth, $m) || !hash_equals($token, trim($m[1]))) {
    respondJson(['ok' => false, 'error' => 'Unauthorized'], 401);
}

$url = '';
$ct  = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($ct, 'application/json') !== false) {
    $body = json_decode(file_get_contents('php://input'), true);
    $url  = (string)($body['url'] ?? '');
} else {
    $url = (string)($_POST['url'] ?? '');
}

$r = createLink(db(), $url, $_SERVER['REMOTE_ADDR'] ?? null);
respondJson($r, $r['ok'] ? 201 : 422);
