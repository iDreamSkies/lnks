<?php
/**
 * lnks — front controller
 * Handles the homepage (shorten form) and short-code redirects.
 */
require __DIR__ . '/bootstrap.php';

$uri = trim((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
if ($uri === 'index.php') $uri = '';

/* ── Redirect by short code ──────────────────────────────────────── */
if ($uri !== '' && preg_match('~^[a-zA-Z0-9]{4,12}$~', $uri)) {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method !== 'GET' && $method !== 'HEAD') {
        header('Allow: GET, HEAD');
        http_response_code(405);
        exit;
    }

    $pdo = db();
    $st  = $pdo->prepare('SELECT id, url, status FROM links WHERE code = :c LIMIT 1');
    $st->execute([':c' => $uri]);
    $link = $st->fetch();

    header('X-Robots-Tag: noindex');

    if (!$link || (int)$link['status'] !== 1) {
        http_response_code(404);
        renderLayout('Not found', notFoundBody('This short link does not exist or was disabled.'), ['nav' => 'public']);
        exit;
    }

    if (!isBotRequest()) recordClick($pdo, (int)$link['id']);

    header('Cache-Control: private, no-cache');
    header('Location: ' . $link['url'], true, 302);
    exit;
}

if ($uri !== '') {
    http_response_code(404);
    header('X-Robots-Tag: noindex');
    renderLayout('Not found', notFoundBody('Page not found.'), ['nav' => 'public']);
    exit;
}

/* ── Homepage: public shorten form ───────────────────────────────── */
$result = null;
$error  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    if (!cfg()['public_form'] && !isAdmin()) {
        $error = 'Public shortening is disabled on this instance.';
    } else {
        $pdo = db();
        $ip  = clientIp();
        if (!isAdmin() && !rateLimitOk($pdo, $ip)) {
            $error = 'Rate limit reached. Try again later.';
        } else {
            $r = createLink($pdo, (string)($_POST['url'] ?? ''), $ip);
            if ($r['ok']) $result = $r; else $error = $r['error'];
        }
    }
}

$canShorten = cfg()['public_form'] || isAdmin();

if ($canShorten) {
    $formHtml = '
    <form method="post" class="shorten" action="/">
        ' . csrfField() . '
        <label class="sr-only" for="url">Long URL</label>
        <input type="url" id="url" name="url" placeholder="https://example.com/very/long/url" required maxlength="2048" autofocus>
        <button type="submit" class="btn">Shorten</button>
    </form>';
} else {
    $formHtml = '<p class="notice">This instance is private. Use the <a href="/admin.php">admin panel</a> or the API.</p>';
}

$resultHtml = '';
if ($result) {
    $resultHtml = '
    <div class="result" role="status">
        <input type="text" value="' . e($result['short_url']) . '" readonly data-select aria-label="Short URL">
        <button type="button" class="btn" data-copy="' . e($result['short_url']) . '">Copy</button>
    </div>
    <p class="muted small">Points to ' . e(mb_strimwidth($result['url'], 0, 80, '…')) . '</p>';
} elseif ($error) {
    $resultHtml = '<p class="alert error" role="alert">' . e($error) . '</p>';
}

renderLayout('URL shortener', '
    <section class="hero">
        <h1>Short links,<br>no strings<span class="accent">.</span></h1>
        <p class="tagline">Minimal self-hosted URL shortener. No accounts, no tracking pixels, no bloat.</p>
        ' . $formHtml . $resultHtml . '
    </section>
    <section class="features">
        <div class="card"><h2>Fast</h2><p class="muted">Plain PHP and SQLite. A redirect is a single indexed lookup.</p></div>
        <div class="card"><h2>Private</h2><p class="muted">No third-party scripts. Bots and prefetches are not counted as clicks.</p></div>
        <div class="card"><h2>Open</h2><p class="muted">MIT licensed. Create links here, in the admin panel or over the REST API.</p></div>
    </section>
', [
    'nav'         => 'public',
    'index'       => true,
    'canonical'   => baseUrl() . '/',
    'description' => 'Free self-hosted URL shortener: create short links, count clicks, manage everything from a simple admin panel or REST API.',
]);

function notFoundBody(string $msg): string {
    return '<section class="hero"><h1>404</h1><p class="tagline">' . e($msg) . '</p><p><a class="btn" href="/">Back to homepage</a></p></section>';
}
