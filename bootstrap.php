<?php
/**
 * lnks — bootstrap
 * Shared helpers: config, database (+ migrations), sessions, CSRF, codes, links, stats.
 */

require_once __DIR__ . '/layout.php';

const LNKS_SCHEMA_VERSION = 6;

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
        'domains'      => [],      // extra hosts that serve short links, e.g. ['go.example.com']
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

/*
 * Read helpers that close the cursor right away. An open cursor keeps a SQLite read
 * transaction alive; a later write on the same connection then fails with SQLITE_BUSY
 * (no retry) if another request committed in between. Use these before any write.
 */
function scalar(PDO $pdo, string $sql, array $params = []) {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $v = $st->fetchColumn();
    $st->closeCursor();
    return $v;
}

function row(PDO $pdo, string $sql, array $params = []): ?array {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    $st->closeCursor();
    return $r ?: null;
}

/** Creates the schema on a fresh DB and upgrades databases from older versions. */
function migrate(PDO $pdo): void {
    if ((int)scalar($pdo, 'PRAGMA user_version') >= LNKS_SCHEMA_VERSION) return;

    $pdo->exec('BEGIN IMMEDIATE');
    try {
        // Another request may have migrated while we waited for the write lock
        if ((int)scalar($pdo, 'PRAGMA user_version') >= LNKS_SCHEMA_VERSION) {
            $pdo->exec('COMMIT');
            return;
        }
        $pdo->exec(file_get_contents(__DIR__ . '/schema.sql'));   // all statements are IF NOT EXISTS
        $cols = array_column($pdo->query('PRAGMA table_info(links)')->fetchAll(), 'name');
        // Columns added after v1. ADD COLUMN keeps existing rows; new columns start as NULL.
        $added = ['title' => 'TEXT', 'last_click_at' => 'TEXT', 'expires_at' => 'TEXT', 'max_clicks' => 'INTEGER', 'password_hash' => 'TEXT', 'domain' => 'TEXT'];
        foreach ($added as $col => $type) {
            if (!in_array($col, $cols, true)) $pdo->exec("ALTER TABLE links ADD COLUMN $col $type");
        }
        if (!in_array('last_click_at', $cols, true)) {
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

/** Host of the current request, lower-case, without the port. */
function requestHost(): string {
    return strtolower(preg_replace('~:\d+$~', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
}

/** Hosts that serve short links: the primary one (base_url) plus config 'domains'. */
function allowedHosts(): array {
    $hosts = [hostOf(baseUrl())];
    foreach ((array)cfg()['domains'] as $d) {
        $d = strtolower(trim((string)$d));
        if ($d !== '' && preg_match('~^[a-z0-9.-]+$~', $d)) $hosts[] = $d;
    }
    return array_values(array_unique(array_filter($hosts)));
}

/** Validate a domain binding: empty = any host. Returns [host|null, error|null]. */
function parseDomain($v): array {
    $v = strtolower(trim((string)$v));
    if ($v === '') return [null, null];
    return in_array($v, allowedHosts(), true) ? [$v, null] : [null, t('err.domain')];
}

/** Full short URL of a link, on its bound domain if it has one. */
function shortUrl(array $l): string {
    if (empty($l['domain'])) return baseUrl() . '/' . $l['code'];
    return (parse_url(baseUrl(), PHP_URL_SCHEME) ?: 'https') . '://' . $l['domain'] . '/' . $l['code'];
}

/** True if the URL points at one of our own hosts (would create a redirect loop). */
function isOwnUrl(string $url): bool {
    $h = hostOf($url);
    return $h !== '' && in_array($h, allowedHosts(), true);
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
    return (int)scalar($pdo, 'SELECT COUNT(*) FROM login_attempts WHERE ip = :ip AND ts > :t',
        [':ip' => $ip, ':t' => gmdate('Y-m-d H:i:s', time() - $windowMin * 60)]) >= $max;
}

function loginFailed(PDO $pdo, string $ip): void {
    $pdo->prepare('INSERT INTO login_attempts (ip, ts) VALUES (:ip, :t)')->execute([':ip' => $ip, ':t' => now()]);
}

/* ── Short code ──────────────────────────────────────────────────── */

/** Words that can never be short codes: they collide with files, folders or entry points. */
const RESERVED_CODES = ['admin', 'api', 'install', 'index', 'router', 'bootstrap', 'layout', 'i18n', 'csv', 'config',
    'schema', 'public', 'storage', 'lang', 'tests', 'docs', 'scripts', 'vendor', 'readme', 'license', 'changelog',
    'login', 'logout', 'static', 'assets', 'favicon', 'robots', 'sitemap', 'well-known'];

/**
 * Valid short code: generated codes are 4–12 alphanumerics; imported ones (e.g. from YOURLS)
 * may be 1–32 chars of letters, digits, "-" and "_", starting with a letter or digit.
 */
function isValidCode(string $code): bool {
    return (bool)preg_match('~^[A-Za-z0-9][A-Za-z0-9_-]{0,31}$~', $code)
        && !in_array(strtolower($code), RESERVED_CODES, true);
}

function generateCode(PDO $pdo): string {
    $c        = cfg()['code'];
    $alphabet = $c['alphabet'];
    $max      = strlen($alphabet) - 1;
    $base     = max(4, min(12, (int)$c['length']));

    // Grow the code by one char after 10 collisions in a row (only matters when the space fills up).
    for ($len = $base; $len <= 12; $len++) {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = '';
            for ($i = 0; $i < $len; $i++) $code .= $alphabet[random_int(0, $max)];
            if (isValidCode($code) && !scalar($pdo, 'SELECT 1 FROM links WHERE code = :c LIMIT 1', [':c' => $code])) return $code;
        }
    }
    throw new RuntimeException('Short code space exhausted');
}

/* ── Link options: expiry and click limit ────────────────────────── */

/**
 * Parse an expiry date (always UTC unless the string carries an offset).
 * Accepts "YYYY-MM-DD", "YYYY-MM-DD HH:MM[:SS]", "YYYY-MM-DDTHH:MM" (datetime-local) and ISO 8601 with Z/±HH:MM.
 * A bare date means the end of that day. Returns [value|null, error|null]; empty input = no expiry.
 */
function parseExpiry($v, bool $requireFuture = true): array {
    if ($v === null || (is_string($v) && trim($v) === '')) return [null, null];
    $v = trim((string)$v);
    if (!preg_match('~^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:?\d{2})?)?$~', $v, $m)) {
        return [null, t('err.expires_format')];
    }
    try {
        $dt = new DateTimeImmutable(isset($m[1]) ? $v : $v . ' 23:59:59', new DateTimeZone('UTC'));
    } catch (Exception $ex) {
        return [null, t('err.expires_format')];
    }
    // Reject values PHP would silently roll over (2026-02-31, 12:61): the parsed value must read back the same
    $hasTime = isset($m[1]) && $m[1] !== '';
    if ($dt->format($hasTime ? 'Y-m-d H:i' : 'Y-m-d') !== substr(str_replace('T', ' ', $v), 0, $hasTime ? 16 : 10)) {
        return [null, t('err.expires_format')];
    }
    $utc = $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    if ($requireFuture && $utc <= now()) return [null, t('err.expires_past')];
    return [$utc, null];
}

/** Parse a click limit: empty = unlimited. Returns [int|null, error|null]. */
function parseMaxClicks($v): array {
    if ($v === null || (is_string($v) && trim($v) === '')) return [null, null];
    if (is_int($v) || (is_string($v) && preg_match('~^\s*\d{1,10}\s*$~', $v))) {
        $n = (int)$v;
        if ($n >= 1 && $n <= 1000000000) return [$n, null];
    }
    return [null, t('err.max_clicks')];
}

/** Validate a link password: empty = none. Returns [hash|null, error|null]. */
function parseLinkPassword($v): array {
    if ($v === null || $v === '') return [null, null];
    $v = (string)$v;
    $len = strlen($v);
    if ($len < 4 || $len > 128) return [null, t('err.password')];
    return [password_hash($v, PASSWORD_DEFAULT), null];
}

/** Throttle password guessing on protected links: $max failures per link and IP per window. */
function unlockBlocked(PDO $pdo, int $linkId, string $ip, int $max = 5, int $windowMin = 15): bool {
    $pdo->prepare('DELETE FROM unlock_attempts WHERE ts < :t')->execute([':t' => gmdate('Y-m-d H:i:s', time() - 86400)]);
    return (int)scalar($pdo, 'SELECT COUNT(*) FROM unlock_attempts WHERE link_id = :l AND ip = :ip AND ts > :t',
        [':l' => $linkId, ':ip' => $ip, ':t' => gmdate('Y-m-d H:i:s', time() - $windowMin * 60)]) >= $max;
}

function unlockFailed(PDO $pdo, int $linkId, string $ip): void {
    $pdo->prepare('INSERT INTO unlock_attempts (link_id, ip, ts) VALUES (:l, :ip, :t)')
        ->execute([':l' => $linkId, ':ip' => $ip, ':t' => now()]);
}

/** 'active' | 'disabled' | 'expired' (date passed) | 'limit' (click limit reached) */
function linkState(array $l): string {
    if ((int)$l['status'] !== 1) return 'disabled';
    if (!empty($l['expires_at']) && $l['expires_at'] <= now()) return 'expired';
    if ($l['max_clicks'] !== null && $l['max_clicks'] !== '' && (int)$l['clicks_total'] >= (int)$l['max_clicks']) return 'limit';
    return 'active';
}

/** SQL condition for a link state (named parameter :now must be bound to now()). */
function stateSql(string $state): string {
    $gone = "((expires_at IS NOT NULL AND expires_at <= :now) OR (max_clicks IS NOT NULL AND clicks_total >= max_clicks))";
    switch ($state) {
        case 'active':   return "(status = 1 AND NOT $gone)";
        case 'expired':  return "(status = 1 AND $gone)";
        case 'disabled': return '(status = 0)';
    }
    return '1';
}

/* ── UTM tags ────────────────────────────────────────────────────── */

const UTM_FIELDS = ['source', 'medium', 'campaign'];

/** Normalise UTM input (keys source/medium/campaign or utm_*). Returns [array utm_* => value, error|null]. */
function parseUtm($in): array {
    $out = [];
    if (!is_array($in)) return [[], null];
    foreach (UTM_FIELDS as $f) {
        $v = $in[$f] ?? $in['utm_' . $f] ?? '';
        if (!is_scalar($v)) return [[], t('utm.err_value')];
        $v = trim(preg_replace('~[\x00-\x1f\x7f]+~', ' ', (string)$v));
        if (strlen($v) > 100) return [[], t('utm.err_value')];
        if ($v !== '') $out['utm_' . $f] = $v;
    }
    return [$out, null];
}

/**
 * Add UTM tags to a URL. The query string is edited as text, so other parameters keep their exact
 * spelling (parse_str would mangle keys like "a.b" or "x[]"); existing tags with the same name are
 * replaced and the #fragment stays at the end.
 */
function applyUtm(string $url, array $utm): string {
    if (!$utm) return $url;
    $frag = '';
    if (($p = strpos($url, '#')) !== false) {
        $frag = substr($url, $p);
        $url  = substr($url, 0, $p);
    }
    [$base, $query] = array_pad(explode('?', $url, 2), 2, '');
    $pairs = [];
    foreach ($query === '' ? [] : explode('&', $query) as $pair) {
        if ($pair === '' || isset($utm[urldecode(explode('=', $pair, 2)[0])])) continue;
        $pairs[] = $pair;
    }
    foreach ($utm as $k => $v) $pairs[] = $k . '=' . rawurlencode($v);
    return $base . '?' . implode('&', $pairs) . $frag;
}

/** @return array<int,array> all UTM templates, by name */
function utmTemplates(PDO $pdo): array {
    return $pdo->query('SELECT * FROM utm_templates ORDER BY name COLLATE NOCASE')->fetchAll();
}

/** Find a template by id or name. */
function findUtmTemplate(PDO $pdo, $ref): ?array {
    if ($ref === null || $ref === '') return null;
    return is_int($ref) || preg_match('~^\d+$~', (string)$ref)
        ? row($pdo, 'SELECT * FROM utm_templates WHERE id = :i', [':i' => (int)$ref])
        : row($pdo, 'SELECT * FROM utm_templates WHERE name = :n', [':n' => (string)$ref]);
}

function saveUtmTemplate(PDO $pdo, string $name, array $fields): array {
    $name = trim(preg_replace('~[\x00-\x1f\x7f]+~', ' ', $name));
    if ($name === '' || strlen($name) > 60) return ['ok' => false, 'error' => t('utm.err_name')];
    [$utm, $err] = parseUtm($fields);
    if ($err) return ['ok' => false, 'error' => $err];
    if (!$utm) return ['ok' => false, 'error' => t('utm.err_empty')];
    try {
        $pdo->prepare('INSERT INTO utm_templates (name, source, medium, campaign, created_at) VALUES (:n, :s, :m, :c, :t)')
            ->execute([':n' => $name, ':s' => $utm['utm_source'] ?? null, ':m' => $utm['utm_medium'] ?? null,
                       ':c' => $utm['utm_campaign'] ?? null, ':t' => now()]);
    } catch (PDOException $ex) {
        if ($ex->getCode() === '23000') return ['ok' => false, 'error' => t('utm.err_dup')];
        throw $ex;
    }
    return ['ok' => true, 'id' => (int)$pdo->lastInsertId()];
}

/** UTM tags for a new link: template values first, explicit fields override. Returns [utm, error|null]. */
function resolveUtm(PDO $pdo, $templateRef, $fields): array {
    $base = [];
    if ($templateRef !== null && $templateRef !== '') {
        $tpl = findUtmTemplate($pdo, $templateRef);
        if (!$tpl) return [[], t('utm.err_unknown')];
        [$base] = parseUtm($tpl);
    }
    [$own, $err] = parseUtm($fields);
    if ($err) return [[], $err];
    $merged = [];   // always source → medium → campaign, whichever side a tag came from
    foreach (UTM_FIELDS as $f) {
        $v = $own['utm_' . $f] ?? $base['utm_' . $f] ?? null;
        if ($v !== null) $merged['utm_' . $f] = $v;
    }
    return [$merged, null];
}

/* ── Create / update link ─────────────────────────────────────────── */

/**
 * @param array $opt title?, expires_at?, max_clicks?, password?, utm? (array utm_* => value, see resolveUtm),
 *                   code? (custom alias), domain? (one of allowedHosts(); empty = any)
 */
function createLink(PDO $pdo, string $url, ?string $ip = null, array $opt = []): array {
    $url = trim($url);
    if (!isValidUrl($url)) {
        return ['ok' => false, 'error' => t('err.invalid_url')];
    }
    if (!empty($opt['utm'])) {
        $url = applyUtm($url, $opt['utm']);
        if (!isValidUrl($url)) return ['ok' => false, 'error' => t('err.invalid_url')];
    }
    if (strlen($url) > 2048) {
        return ['ok' => false, 'error' => t('err.too_long')];
    }
    if (isOwnUrl($url)) {
        return ['ok' => false, 'error' => t('err.self')];
    }
    $alias = trim((string)($opt['code'] ?? ''));
    if ($alias !== '' && !isValidCode($alias)) return ['ok' => false, 'error' => t('err.alias')];
    [$domain, $err] = parseDomain($opt['domain'] ?? '');
    if ($err) return ['ok' => false, 'error' => $err];
    $title = cleanTitle($opt['title'] ?? null);
    [$expires, $err] = parseExpiry($opt['expires_at'] ?? null);
    if ($err) return ['ok' => false, 'error' => $err];
    [$maxClicks, $err] = parseMaxClicks($opt['max_clicks'] ?? null);
    if ($err) return ['ok' => false, 'error' => $err];
    [$pwHash, $err] = parseLinkPassword($opt['password'] ?? null);
    if ($err) return ['ok' => false, 'error' => $err];

    // The UNIQUE constraint is the source of truth; retry on a (very rare) race.
    for ($try = 0; $try < 3; $try++) {
        $code = $alias !== '' ? $alias : generateCode($pdo);
        try {
            $pdo->prepare('INSERT INTO links (code, url, title, expires_at, max_clicks, password_hash, domain, created_ip, created_at)
                           VALUES (:c, :u, :t, :e, :m, :p, :d, :i, :a)')
                ->execute([':c' => $code, ':u' => $url, ':t' => $title, ':e' => $expires, ':m' => $maxClicks,
                           ':p' => $pwHash, ':d' => $domain, ':i' => $ip, ':a' => now()]);
            return ['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'code' => $code, 'domain' => $domain,
                    'short_url' => shortUrl(['code' => $code, 'domain' => $domain]), 'url' => $url, 'title' => $title,
                    'expires_at' => $expires, 'max_clicks' => $maxClicks, 'protected' => $pwHash !== null];
        } catch (PDOException $ex) {
            if ($ex->getCode() !== '23000') throw $ex;
            if ($alias !== '') return ['ok' => false, 'error' => t('err.alias_taken', ['code' => $alias])];
        }
    }
    return ['ok' => false, 'error' => t('err.alloc')];
}

function cleanTitle($title): ?string {
    if ($title === null) return null;
    $title = trim(preg_replace('~[\x00-\x1f\x7f]+~', ' ', (string)$title));
    return $title === '' ? null : cut($title, 120);
}

/**
 * Update editable fields of a link. Only keys present in $f are changed:
 * title, expires_at, max_clicks (null/'' clears), status (0|1),
 * password (non-empty string sets a new one; null or '' removes it).
 */
function updateLink(PDO $pdo, int $id, array $f): array {
    $set = [];
    $params = [':id' => $id];
    if (array_key_exists('title', $f)) {
        $set[] = 'title = :title';
        $params[':title'] = cleanTitle($f['title']);
    }
    if (array_key_exists('expires_at', $f)) {
        [$v, $err] = parseExpiry($f['expires_at']);
        if ($err) return ['ok' => false, 'error' => $err];
        $set[] = 'expires_at = :exp';
        $params[':exp'] = $v;
    }
    if (array_key_exists('max_clicks', $f)) {
        [$v, $err] = parseMaxClicks($f['max_clicks']);
        if ($err) return ['ok' => false, 'error' => $err];
        $set[] = 'max_clicks = :max';
        $params[':max'] = $v;
    }
    if (array_key_exists('password', $f)) {
        [$v, $err] = parseLinkPassword($f['password']);
        if ($err) return ['ok' => false, 'error' => $err];
        $set[] = 'password_hash = :pw';
        $params[':pw'] = $v;
    }
    if (array_key_exists('status', $f)) {
        if (!in_array($f['status'], [0, 1, '0', '1', false, true], true)) return ['ok' => false, 'error' => 'status must be 0 or 1'];
        $set[] = 'status = :st';
        $params[':st'] = (int)$f['status'];
    }
    if ($set) $pdo->prepare('UPDATE links SET ' . implode(', ', $set) . ' WHERE id = :id')->execute($params);
    return ['ok' => true];
}

/* ── Simple per-IP rate limit for the public form ────────────────── */
function rateLimitOk(PDO $pdo, string $ip): bool {
    $rl    = cfg()['rate_limit'];
    $since = gmdate('Y-m-d H:i:s', time() - (int)$rl['window_min'] * 60);
    return (int)scalar($pdo, 'SELECT COUNT(*) FROM links WHERE created_ip = :ip AND created_at > :s',
        [':ip' => $ip, ':s' => $since]) < (int)$rl['max'];
}

/* ── Clicks & stats ──────────────────────────────────────────────── */
function isBotRequest(): bool {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') return true;
    if (stripos($_SERVER['HTTP_SEC_PURPOSE'] ?? ($_SERVER['HTTP_PURPOSE'] ?? ''), 'prefetch') !== false) return true;
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    return $ua === '' || (bool)preg_match('~bot|crawl|spider|slurp|preview|facebookexternalhit|curl|wget|python-requests|headless|monitor|uptime~i', $ua);
}

/**
 * Count a click unless the link's click limit is already reached (checked atomically,
 * so concurrent requests cannot overshoot the limit). Returns false when the limit is reached.
 */
function recordClick(PDO $pdo, int $linkId): bool {
    $ref = $_SERVER['HTTP_REFERER'] ?? null;
    $ref = $ref !== null && $ref !== '' ? substr($ref, 0, 255) : null;
    $ts  = now();
    $pdo->exec('BEGIN IMMEDIATE');
    $st = $pdo->prepare('UPDATE links SET clicks_total = clicks_total + 1, last_click_at = :t
                         WHERE id = :i AND (max_clicks IS NULL OR clicks_total < max_clicks)');
    $st->execute([':t' => $ts, ':i' => $linkId]);
    if ($st->rowCount() === 0) {
        $pdo->exec('ROLLBACK');
        return false;
    }
    $pdo->prepare('INSERT INTO clicks (link_id, ts, referrer) VALUES (:l, :t, :r)')
        ->execute([':l' => $linkId, ':t' => $ts, ':r' => $ref]);
    $pdo->exec('COMMIT');
    return true;
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
