<?php
/**
 * lnks — REST API (Bearer token)
 *
 *   POST   /api.php                 create   { "url", "title"?, "expires_at"?, "max_clicks"?, "password"? } → 201
 *   GET    /api.php                 list     ?q=&state=active|expired|disabled&limit=20&offset=0   → 200
 *   GET    /api.php?code=aB3xYz     details + stats (?days=7|30|90: daily, hourly, browsers, os,
 *                                   devices, countries, referrers, previous-period total)        → 200
 *   PATCH  /api.php?code=aB3xYz     update   any of { "status", "title", "expires_at", "max_clicks", "password" } → 200
 *                                            (null or "" removes the expiry / click limit / password)
 *   DELETE /api.php?code=aB3xYz     delete                                                          → 200
 *   GET    /api.php?tags=1          all tags with link counts; list links of one tag with ?tag=name  → 200
 *   GET    /api.php?utm_templates=1 saved UTM templates                                             → 200
 *   GET    /api.php?export=csv      all links as CSV (same file as the admin export)                → 200
 *   POST   /api.php?import=csv      import CSV (raw text/csv body or multipart field "file");
 *                                   add &dry_run=1 to only validate                                 → 200
 *
 * tags: ["a", "b"] or "a, b" on POST / PATCH (PATCH replaces the set; [] or "" clears)
 * code (or alias): custom short code, 1–32 of [A-Za-z0-9_-]; domain: bind to one of the configured hosts
 * utm: { "source", "medium", "campaign" } and/or "utm_template": id or name — tags are added to the URL
 * expires_at: UTC "YYYY-MM-DD HH:MM[:SS]", ISO 8601 with offset, or "YYYY-MM-DD" (= end of that day)
 *
 * Header: Authorization: Bearer <api_token>
 */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/csv.php';
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
        'short_url'     => shortUrl($l),
        'url'           => $l['url'],
        'title'         => $l['title'],
        'clicks'        => (int)$l['clicks_total'],
        'last_click_at' => $l['last_click_at'],
        'active'        => (int)$l['status'] === 1,
        'state'         => linkState($l),   // active | disabled | expired | limit
        'expires_at'    => $l['expires_at'],
        'max_clicks'    => $l['max_clicks'] === null ? null : (int)$l['max_clicks'],
        'protected'     => !empty($l['password_hash']),   // the hash itself is never returned
        'domain'        => $l['domain'] ?? null,
        'tags'          => $l['tags'] ?? tagsFor(db(), [(int)$l['id']])[(int)$l['id']],
        'created_at'    => $l['created_at'],
    ];
}

function findByCode(PDO $pdo, string $code): array {
    $l = row($pdo, 'SELECT * FROM links WHERE code = :c', [':c' => $code]);
    if (!$l) respondJson(['ok' => false, 'error' => 'Link not found'], 404);
    return $l;
}

$pdo    = db();
$method = $_SERVER['REQUEST_METHOD'];
$code   = (string)($_GET['code'] ?? '');

/* ── CSV export / import ── */
if ($method === 'GET' && ($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . exportFilename() . '"');
    header('Cache-Control: no-store');
    exportCsv($pdo, fopen('php://output', 'w'));
    exit;
}
if ($method === 'POST' && ($_GET['import'] ?? '') === 'csv') {
    if (isset($_FILES['file'])) {
        $f = $_FILES['file'];
        $text = ($f['error'] ?? 1) === UPLOAD_ERR_OK && is_uploaded_file($f['tmp_name']) && $f['size'] <= CSV_MAX_BYTES
            ? (string)file_get_contents($f['tmp_name']) : null;
    } else {
        $text = (string)file_get_contents('php://input', false, null, 0, CSV_MAX_BYTES + 1);
    }
    if ($text === null || strlen($text) > CSV_MAX_BYTES) {
        respondJson(['ok' => false, 'error' => 'Upload failed or file larger than 5 MB'], 413);
    }
    $parsed = parseCsv($text);
    $dry    = !empty($_GET['dry_run']);
    if ($dry) {
        $plan = planImport($pdo, $parsed['rows']);
        $res  = ['imported' => 0, 'would_import' => count($plan['new']), 'duplicates' => count($plan['duplicates'])];
    } else {
        $res = runImport($pdo, $parsed['rows']);
    }
    respondJson(['ok' => true, 'dry_run' => $dry, 'format' => $parsed['format'], 'rows' => $parsed['total']] + $res
        + ['errors' => array_map(fn($e) => ['line' => $e['line'], 'error' => $e['error']], $parsed['errors'])]);
}

if ($method === 'GET' && !empty($_GET['tags'])) {
    $all = allTags($pdo);
    respondJson(['ok' => true, 'items' => array_map(fn($n, $c) => ['name' => $n, 'links' => $c], array_keys($all), $all)]);
}

if ($method === 'GET' && !empty($_GET['utm_templates'])) {
    respondJson(['ok' => true, 'items' => array_map(fn($t) => [
        'id' => (int)$t['id'], 'name' => $t['name'],
        'source' => $t['source'], 'medium' => $t['medium'], 'campaign' => $t['campaign'],
    ], utmTemplates($pdo))]);
}

switch ($method) {
    case 'POST':
        $d = requestData();
        [$utm, $err] = resolveUtm($pdo, $d['utm_template'] ?? null, is_array($d['utm'] ?? null) ? $d['utm'] : []);
        if ($err) respondJson(['ok' => false, 'error' => $err], 422);
        $r = createLink($pdo, (string)($d['url'] ?? ''), clientIp(), [
            'utm'        => $utm,
            'code'       => isset($d['code']) ? (string)$d['code'] : (isset($d['alias']) ? (string)$d['alias'] : ''),
            'domain'     => isset($d['domain']) ? (string)$d['domain'] : '',
            'tags'       => $d['tags'] ?? null,
            'title'      => isset($d['title']) ? (string)$d['title'] : null,
            'expires_at' => $d['expires_at'] ?? null,
            'max_clicks' => $d['max_clicks'] ?? null,
            'password'   => isset($d['password']) ? (string)$d['password'] : null,
        ]);
        if ($r['ok']) $r['state'] = 'active';
        respondJson($r, $r['ok'] ? 201 : 422);

    case 'GET':
        if ($code !== '') {
            $l = findByCode($pdo, $code);
            $days = periodDays($_GET['days'] ?? null);
            $stats = linkStats($pdo, (int)$l['id'], $days);
            $stats['referrers'] = topReferrers($pdo, (int)$l['id'], 10, array_key_first($stats['daily']) . ' 00:00:00');
            respondJson(['ok' => true, 'link' => linkOut($l) + ['daily_clicks' => dailyClicks($pdo, [(int)$l['id']], 30), 'stats' => $stats]]);
        }
        $q      = trim((string)($_GET['q'] ?? ''));
        $limit  = max(1, min(100, (int)($_GET['limit'] ?? 20)));
        $offset = max(0, (int)($_GET['offset'] ?? 0));
        $conds  = [];
        $params = [];
        if ($q !== '') {
            $conds[] = "(code LIKE :q ESCAPE '\\' OR url LIKE :q ESCAPE '\\' OR title LIKE :q ESCAPE '\\')";
            $params[':q'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        }
        $tag = trim((string)($_GET['tag'] ?? ''));
        if ($tag !== '') {
            $conds[] = tagSql();
            $params[':tag'] = $tag;
        }
        $state = (string)($_GET['state'] ?? '');
        if (in_array($state, ['active', 'expired', 'disabled'], true)) {
            $conds[] = stateSql($state);
            if ($state !== 'disabled') $params[':now'] = now();
        }
        $where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';
        $st = $pdo->prepare("SELECT COUNT(*) FROM links $where");
        $st->execute($params);
        $total = (int)$st->fetchColumn();
        $st = $pdo->prepare("SELECT * FROM links $where ORDER BY id DESC LIMIT :lim OFFSET :off");
        foreach ($params as $k => $v) $st->bindValue($k, $v);
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->bindValue(':off', $offset, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll();
        $tagMap = tagsFor($pdo, array_column($rows, 'id'));   // one query for the whole page
        foreach ($rows as &$r) $r['tags'] = $tagMap[(int)$r['id']];
        unset($r);
        respondJson(['ok' => true, 'total' => $total, 'limit' => $limit, 'offset' => $offset,
                     'items' => array_map('linkOut', $rows)]);

    case 'PATCH':
        $l = findByCode($pdo, $code);
        $d = array_intersect_key(requestData(), array_flip(['status', 'title', 'expires_at', 'max_clicks', 'password', 'tags']));
        if (!$d) {
            respondJson(['ok' => false, 'error' => 'Provide at least one of: status, title, expires_at, max_clicks, password, tags'], 422);
        }
        $r = updateLink($pdo, (int)$l['id'], $d);
        if (!$r['ok']) respondJson($r, 422);
        respondJson(['ok' => true, 'link' => linkOut(findByCode($pdo, $code))]);

    case 'DELETE':
        $l = findByCode($pdo, $code);
        $pdo->prepare('DELETE FROM links WHERE id = :i')->execute([':i' => $l['id']]);
        pruneTags($pdo);
        respondJson(['ok' => true, 'deleted' => $code]);

    default:
        header('Allow: GET, POST, PATCH, DELETE');
        respondJson(['ok' => false, 'error' => 'Method not allowed'], 405);
}
