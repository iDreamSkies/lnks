<?php
/**
 * lnks — bootstrap
 * Shared helpers: config, database, CSRF, code generation.
 */

if (!file_exists(__DIR__ . '/config.php')) {
    // Not installed yet — send the user to the web installer
    if (basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'install.php') {
        header('Location: /install.php');
        exit;
    }
}

function cfg(): array {
    static $cfg = null;
    return $cfg ??= require __DIR__ . '/config.php';
}

date_default_timezone_set(cfg()['timezone']);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('lnks_sess');
    session_start();
}

/* ── Database ────────────────────────────────────────────────────── */
function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO('sqlite:' . cfg()['db_path']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys=ON; PRAGMA journal_mode=WAL;');
    // Initialize schema if the links table is missing (fresh or empty DB file)
    $has = $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='links'")->fetchColumn();
    if (!$has) {
        $pdo->exec(file_get_contents(__DIR__ . '/schema.sql'));
    }
    return $pdo;
}

/* ── Helpers ─────────────────────────────────────────────────────── */
function now(): string {
    return gmdate('Y-m-d H:i:s');
}

function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function baseUrl(): string {
    $c = cfg()['base_url'];
    if ($c !== '') return rtrim($c, '/');
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

function isValidUrl(string $url): bool {
    return (bool)preg_match('~^https?://~i', $url)
        && filter_var($url, FILTER_VALIDATE_URL) !== false;
}

function respondJson(array $d, int $c = 200): void {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($c);
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* ── CSRF ────────────────────────────────────────────────────────── */
function csrfToken(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrfField(): string {
    return '<input type="hidden" name="csrf" value="' . e(csrfToken()) . '">';
}

function csrfCheck(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '-')) {
        http_response_code(400);
        exit('Bad CSRF token');
    }
}

/* ── Auth (single admin) ─────────────────────────────────────────── */
function isAdmin(): bool {
    return !empty($_SESSION['admin']);
}

function requireAdmin(): void {
    if (!isAdmin()) {
        header('Location: /admin.php');
        exit;
    }
}

/* ── Short code ──────────────────────────────────────────────────── */
function generateCode(PDO $pdo): string {
    $c = cfg()['code'];
    $alphabet = $c['alphabet'];
    $len      = max(4, (int)$c['length']);
    $max      = strlen($alphabet) - 1;
    $st       = $pdo->prepare('SELECT 1 FROM links WHERE code = :c LIMIT 1');

    for ($attempt = 0; $attempt < 10; $attempt++) {
        $code = '';
        for ($i = 0; $i < $len; $i++) {
            $code .= $alphabet[random_int(0, $max)];
        }
        $st->execute([':c' => $code]);
        if (!$st->fetchColumn()) return $code;
    }
    // Extremely unlikely: widen by one char and try once more
    return generateCode($pdo) . $alphabet[random_int(0, $max)];
}

/* ── Create link ─────────────────────────────────────────────────── */
function createLink(PDO $pdo, string $url, ?string $ip = null): array {
    $url = trim($url);
    if (!isValidUrl($url)) {
        return ['ok' => false, 'error' => 'Invalid URL. Only http/https links are accepted.'];
    }
    if (strlen($url) > 2048) {
        return ['ok' => false, 'error' => 'URL is too long (max 2048 characters).'];
    }
    $code = generateCode($pdo);
    $pdo->prepare('INSERT INTO links (code, url, created_ip, created_at) VALUES (:c, :u, :i, :t)')
        ->execute([':c' => $code, ':u' => $url, ':i' => $ip, ':t' => now()]);
    return ['ok' => true, 'code' => $code, 'short_url' => baseUrl() . '/' . $code, 'url' => $url];
}

/* ── Simple per-IP rate limit for the public form ────────────────── */
function rateLimitOk(PDO $pdo, string $ip, int $max = 20, int $windowMin = 60): bool {
    $since = gmdate('Y-m-d H:i:s', time() - $windowMin * 60);
    $st = $pdo->prepare("SELECT COUNT(*) FROM links WHERE created_ip = :ip AND created_at > :s");
    $st->execute([':ip' => $ip, ':s' => $since]);
    return (int)$st->fetchColumn() < $max;
}
