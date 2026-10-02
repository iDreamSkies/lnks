<?php
/**
 * lnks — REST API (Bearer token)
 *
 *   POST   /api.php                 create   { "url": "https://…", "title": "optional" }   → 201
 *   GET    /api.php                 list     ?q=&limit=20&offset=0                         → 200
 *   GET    /api.php?code=aB3xYz     details  + clicks for the last 30 days                 → 200
 *   PATCH  /api.php?code=aB3xYz     update   { "status": 0|1 }  (disable / enable)         → 200
 *   DELETE /api.php?code=aB3xYz     delete                                                 → 200
 *
 * Header: Authorization: Bearer <api_token>
 */
require __DIR__ . '/bootstrap.php';
currentLang('en');   // machine-readable API: messages are always English

$token = cfg()['api_token'];
if ($token === '') {
    respondJson(['ok' => false, 'error' => 'API is disabled (api_token not configured)'], 503);
}

$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if ($auth === '' && function_exists('getallheaders')) {
    foreach (getallheaders() as $k => $v) if (strcasecmp($k, 'Authorization') === 0) $auth = $v;
}
if (!preg_match('~^Bearer\s+(.+)$~i', $auth, $m) || !hash_equals($token, trim($m[1]))) {
    header('WWW-Authenticate: Bearer');
    respondJson(['ok' => false, 'error' => 'Unauthorized'], 401);
}

/** @return array JSON body, or form fields when the body is not JSON */
function requestData(): array {
    if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        if (trim($raw) === '') return [];
        $body = json_decode($raw, true);
        if (!is_array($body)) respondJson(['ok' => false, 'error' => 'Invalid JSON body'], 400);
        return $body;
    }
    return $_POST;
}

function linkOut(array $l): array {
    return [
        'id'            => (int)$l['id'],
        'code'          => $l['code'],
        'short_url'     => baseUrl() . '/' . $l['code'],
        'url'           => $l['url'],
        'title'         => $l['title'],
        'clicks'        => (int)$l['clicks_total'],
        'last_click_at' => $l['last_click_at'],
        'active'        => (int)$l['status'] === 1,
        'created_at'    => $l['created_at'],
    ];
}

function findByCode(PDO $pdo, string $code): array {
    $st = $pdo->prepare('SELECT * FROM links WHERE code = :c');
    $st->execute([':c' => $code]);
    $l = $st->fetch();
    if (!$l) respondJson(['ok' => false, 'error' => 'Link not found'], 404);
    return $l;
}

$pdo    = db();
$method = $_SERVER['REQUEST_METHOD'];
$code   = (string)($_GET['code'] ?? '');

switch ($method) {
    case 'POST':
        $d = requestData();
        $r = createLink($pdo, (string)($d['url'] ?? ''), clientIp(), isset($d['title']) ? (string)$d['title'] : null);
        respondJson($r, $r['ok'] ? 201 : 422);

    case 'GET':
        if ($code !== '') {
            $l = findByCode($pdo, $code);
            respondJson(['ok' => true, 'link' => linkOut($l) + ['daily_clicks' => dailyClicks($pdo, [(int)$l['id']], 30)]]);
        }
        $q      = trim((string)($_GET['q'] ?? ''));
        $limit  = max(1, min(100, (int)($_GET['limit'] ?? 20)));
        $offset = max(0, (int)($_GET['offset'] ?? 0));
        $where  = '';
        $params = [];
        if ($q !== '') {
            $where = "WHERE code LIKE :q ESCAPE '\\' OR url LIKE :q ESCAPE '\\' OR title LIKE :q ESCAPE '\\'";
            $params[':q'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        }
        $st = $pdo->prepare("SELECT COUNT(*) FROM links $where");
        $st->execute($params);
        $total = (int)$st->fetchColumn();
        $st = $pdo->prepare("SELECT * FROM links $where ORDER BY id DESC LIMIT :lim OFFSET :off");
        foreach ($params as $k => $v) $st->bindValue($k, $v);
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->bindValue(':off', $offset, PDO::PARAM_INT);
        $st->execute();
        respondJson(['ok' => true, 'total' => $total, 'limit' => $limit, 'offset' => $offset,
                     'items' => array_map('linkOut', $st->fetchAll())]);

    case 'PATCH':
        $l = findByCode($pdo, $code);
        $d = requestData();
        if (!isset($d['status']) || !in_array($d['status'], [0, 1, '0', '1', false, true], true)) {
            respondJson(['ok' => false, 'error' => 'Provide "status": 0 or 1'], 422);
        }
        $pdo->prepare('UPDATE links SET status = :s WHERE id = :i')->execute([':s' => (int)$d['status'], ':i' => $l['id']]);
        respondJson(['ok' => true, 'link' => linkOut(findByCode($pdo, $code))]);

    case 'DELETE':
        $l = findByCode($pdo, $code);
        $pdo->prepare('DELETE FROM links WHERE id = :i')->execute([':i' => $l['id']]);
        respondJson(['ok' => true, 'deleted' => $code]);

    default:
        header('Allow: GET, POST, PATCH, DELETE');
        respondJson(['ok' => false, 'error' => 'Method not allowed'], 405);
}
