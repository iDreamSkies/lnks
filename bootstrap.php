<?php
/**
 * lnks — bootstrap
 * Shared helpers: config, database (+ migrations), sessions, CSRF, codes, links, stats.
 */

require_once __DIR__ . '/layout.php';

const LNKS_SCHEMA_VERSION = 2;

if (!file_exists(__DIR__ . '/config.php')) {
    // Not installed yet — send the user to the web installer
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if ($script === 'api.php') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(503);
        exit('{"ok":false,"error":"Not installed"}');
    }
    if ($script !== 'install.php') {
        header('Location: /install.php');
        exit;
    }
}

function cfg(): array {
    static $cfg = null;
    // Defaults first, so configs written by older versions keep working.
    return $cfg ??= array_replace_recursive([
        'app_name'     => 'lnks',
        'base_url'     => '',
        'timezone'     => 'UTC',
        'db_path'      => __DIR__ . '/storage/lnks.sqlite',
        'trust_proxy'  => false,   // read the client IP from X-Forwarded-For (set only behind your own proxy)
        'lang'         => 'auto',  // 'auto' (browser language), 'en' or 'ru' — visitors can still switch
        'public_form'  => true,
        'admin_user'   => 'admin',
        'admin_pass_hash' => '',
        'api_token'    => '',
        'rate_limit'   => ['max' => 20, 'window_min' => 60],
        'code'         => ['alphabet' => 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789', 'length' => 6],
    ], require __DIR__ . '/config.php');
}

date_default_timezone_set(cfg()['timezone']);
currentLang();   // resolve the language (and save ?lang=…) before any output

/* ── Session (started lazily — plain redirects never touch it) ───── */
function startSession(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params(['httponly' => true, 'secure' => $https, 'samesite' => 'Lax', 'path' => '/']);
    session_name('lnks_sess');
    session_start();
}

/* ── Database ────────────────────────────────────────────────────── */
function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO('sqlite:' . cfg()['db_path']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA busy_timeout=5000; PRAGMA foreign_keys=ON; PRAGMA journal_mode=WAL;');
    migrate($pdo);
    return $pdo;
}

/** Creates the schema on a fresh DB and upgrades databases from older versions. */
function migrate(PDO $pdo): void {
    $ver = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
    if ($ver >= LNKS_SCHEMA_VERSION) return;

    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $pdo->exec(file_get_contents(__DIR__ . '/schema.sql'));   // all statements are IF NOT EXISTS
        $cols = array_column($pdo->query('PRAGMA table_info(links)')->fetchAll(), 'name');
        if (!in_array('title', $cols, true))         $pdo->exec('ALTER TABLE links ADD COLUMN title TEXT');
        if (!in_array('last_click_at', $cols, true)) {
            $pdo->exec('ALTER TABLE links ADD COLUMN last_click_at TEXT');
            $pdo->exec('UPDATE links SET last_click_at = (SELECT MAX(ts) FROM clicks WHERE link_id = links.id)');
        }
        $pdo->exec('PRAGMA user_version=' . LNKS_SCHEMA_VERSION);
        $pdo->exec('COMMIT');
    } catch (Throwable $ex) {
        $pdo->exec('ROLLBACK');
        throw $ex;
    }
}

/* ── Helpers ─────────────────────────────────────────────────────── */
function now(): string {
    return gmdate('Y-m-d H:i:s');
}

function baseUrl(): string {
    $c = cfg()['base_url'];
    if ($c !== '') return rtrim($c, '/');
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

function hostOf(string $url): string {
    return strtolower((string)parse_url($url, PHP_URL_HOST));
}

function clientIp(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (cfg()['trust_proxy'] && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // Right-most entry is the one appended by our own proxy; earlier ones are client-controlled.
        $parts = array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));
        $last  = end($parts);
        if (filter_var($last, FILTER_VALIDATE_IP)) $ip = $last;
    }
    return $ip;
}

function isValidUrl(string $url): bool {
    return (bool)preg_match('~^https?://~i', $url)
        && !preg_match('~[\x00-\x20\x7f]~', $url)
        && filter_var($url, FILTER_VALIDATE_URL) !== false;
}

function respondJson(array $d, int $c = 200): void {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    http_response_code($c);
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* ── CSRF ────────────────────────────────────────────────────────── */
function csrfToken(): string {
    startSession();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrfField(): string {
    return '<input type="hidden" name="csrf" value="' . e(csrfToken()) . '">';
}

function csrfCheck(): void {
    startSession();
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? '-'))) {
        http_response_code(400);
        exit('Bad CSRF token');
    }
}

/* ── Auth (single admin) ─────────────────────────────────────────── */
function isAdmin(): bool {
    // No session cookie → cannot be admin; don't create a session for anonymous visitors.
    if (session_status() !== PHP_SESSION_ACTIVE && !isset($_COOKIE['lnks_sess'])) return false;
    startSession();
    return !empty($_SESSION['admin']);
}

function loginBlocked(PDO $pdo, string $ip, int $max = 5, int $windowMin = 15): bool {
    $pdo->prepare('DELETE FROM login_attempts WHERE ts < :t')->execute([':t' => gmdate('Y-m-d H:i:s', time() - 86400)]);
    $st = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = :ip AND ts > :t');
    $st->execute([':ip' => $ip, ':t' => gmdate('Y-m-d H:i:s', time() - $windowMin * 60)]);
    return (int)$st->fetchColumn() >= $max;
}

function loginFailed(PDO $pdo, string $ip): void {
    $pdo->prepare('INSERT INTO login_attempts (ip, ts) VALUES (:ip, :t)')->execute([':ip' => $ip, ':t' => now()]);
}

/* ── Short code ──────────────────────────────────────────────────── */
function generateCode(PDO $pdo): string {
    $c        = cfg()['code'];
    $alphabet = $c['alphabet'];
    $max      = strlen($alphabet) - 1;
    $base     = max(4, min(12, (int)$c['length']));   // index.php routes codes of 4–12 chars
    $st       = $pdo->prepare('SELECT 1 FROM links WHERE code = :c LIMIT 1');

    // Grow the code by one char after 10 collisions in a row (only matters when the space fills up).
    for ($len = $base; $len <= 12; $len++) {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = '';
            for ($i = 0; $i < $len; $i++) $code .= $alphabet[random_int(0, $max)];
            $st->execute([':c' => $code]);
            if (!$st->fetchColumn()) return $code;
        }
    }
    throw new RuntimeException('Short code space exhausted');
}

/* ── Create link ─────────────────────────────────────────────────── */
function createLink(PDO $pdo, string $url, ?string $ip = null, ?string $title = null): array {
    $url = trim($url);
    if (!isValidUrl($url)) {
        return ['ok' => false, 'error' => t('err.invalid_url')];
    }
    if (strlen($url) > 2048) {
        return ['ok' => false, 'error' => t('err.too_long')];
    }
    $own = hostOf(baseUrl());
    if ($own !== '' && hostOf($url) === $own) {
        return ['ok' => false, 'error' => t('err.self')];
    }
    $title = $title !== null ? trim(preg_replace('~[\x00-\x1f\x7f]+~', ' ', $title)) : '';
    $title = $title === '' ? null : cut($title, 120);

    // The UNIQUE constraint is the source of truth; retry on a (very rare) race.
    for ($try = 0; $try < 3; $try++) {
        $code = generateCode($pdo);
        try {
            $pdo->prepare('INSERT INTO links (code, url, title, created_ip, created_at) VALUES (:c, :u, :t, :i, :a)')
                ->execute([':c' => $code, ':u' => $url, ':t' => $title, ':i' => $ip, ':a' => now()]);
            return ['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'code' => $code,
                    'short_url' => baseUrl() . '/' . $code, 'url' => $url, 'title' => $title];
        } catch (PDOException $ex) {
            if ($ex->getCode() !== '23000') throw $ex;
        }
    }
    return ['ok' => false, 'error' => t('err.alloc')];
}

/* ── Simple per-IP rate limit for the public form ────────────────── */
function rateLimitOk(PDO $pdo, string $ip): bool {
    $rl    = cfg()['rate_limit'];
    $since = gmdate('Y-m-d H:i:s', time() - (int)$rl['window_min'] * 60);
    $st = $pdo->prepare('SELECT COUNT(*) FROM links WHERE created_ip = :ip AND created_at > :s');
    $st->execute([':ip' => $ip, ':s' => $since]);
    return (int)$st->fetchColumn() < (int)$rl['max'];
}

/* ── Clicks & stats ──────────────────────────────────────────────── */
function isBotRequest(): bool {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') return true;
    if (stripos($_SERVER['HTTP_SEC_PURPOSE'] ?? ($_SERVER['HTTP_PURPOSE'] ?? ''), 'prefetch') !== false) return true;
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    return $ua === '' || (bool)preg_match('~bot|crawl|spider|slurp|preview|facebookexternalhit|curl|wget|python-requests|headless|monitor|uptime~i', $ua);
}

function recordClick(PDO $pdo, int $linkId): void {
    $ref = $_SERVER['HTTP_REFERER'] ?? null;
    $ref = $ref !== null && $ref !== '' ? substr($ref, 0, 255) : null;
    $ts  = now();
    $pdo->exec('BEGIN IMMEDIATE');
    $pdo->prepare('UPDATE links SET clicks_total = clicks_total + 1, last_click_at = :t WHERE id = :i')
        ->execute([':t' => $ts, ':i' => $linkId]);
    $pdo->prepare('INSERT INTO clicks (link_id, ts, referrer) VALUES (:l, :t, :r)')
        ->execute([':l' => $linkId, ':t' => $ts, ':r' => $ref]);
    $pdo->exec('COMMIT');
}

/** @return array<string,int> date (Y-m-d) => clicks for the last $days days, oldest first. */
function dailyClicks(PDO $pdo, array $linkIds, int $days): array {
    $out = [];
    for ($i = $days - 1; $i >= 0; $i--) $out[gmdate('Y-m-d', time() - $i * 86400)] = 0;
    if (!$linkIds) return $out;
    $in = implode(',', array_fill(0, count($linkIds), '?'));
    $st = $pdo->prepare("SELECT substr(ts,1,10) d, COUNT(*) c FROM clicks WHERE link_id IN ($in) AND ts >= ? GROUP BY d");
    $st->execute([...array_map('intval', $linkIds), array_key_first($out) . ' 00:00:00']);
    foreach ($st->fetchAll() as $r) if (isset($out[$r['d']])) $out[$r['d']] = (int)$r['c'];
    return $out;
}

/** @return array<int,array<string,int>> link_id => (date => clicks) for the last 7 days. */
function sparkData(PDO $pdo, array $linkIds): array {
    $days = [];
    for ($i = 6; $i >= 0; $i--) $days[gmdate('Y-m-d', time() - $i * 86400)] = 0;
    $out = [];
    foreach ($linkIds as $id) $out[(int)$id] = $days;
    if (!$linkIds) return $out;
    $in = implode(',', array_fill(0, count($linkIds), '?'));
    $st = $pdo->prepare("SELECT link_id, substr(ts,1,10) d, COUNT(*) c FROM clicks WHERE link_id IN ($in) AND ts >= ? GROUP BY link_id, d");
    $st->execute([...array_map('intval', $linkIds), array_key_first($days) . ' 00:00:00']);
    foreach ($st->fetchAll() as $r) if (isset($out[$r['link_id']][$r['d']])) $out[$r['link_id']][$r['d']] = (int)$r['c'];
    return $out;
}

/** @return array<string,int> referrer host (or "Direct") => clicks, top $limit. */
function topReferrers(PDO $pdo, int $linkId, int $limit = 10): array {
    $st = $pdo->prepare('SELECT referrer, COUNT(*) c FROM clicks WHERE link_id = :i GROUP BY referrer');
    $st->execute([':i' => $linkId]);
    $agg = [];
    foreach ($st->fetchAll() as $r) {
        $h = $r['referrer'] ? (hostOf($r['referrer']) ?: t('ref.other')) : t('ref.direct');
        $agg[$h] = ($agg[$h] ?? 0) + (int)$r['c'];
    }
    arsort($agg);
    return array_slice($agg, 0, $limit, true);
}
