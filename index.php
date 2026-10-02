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
        renderLayout(t('nf.title'), notFoundBody(t('nf.link')), ['nav' => 'public']);
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
    renderLayout(t('nf.title'), notFoundBody(t('nf.page')), ['nav' => 'public']);
    exit;
}

/* ── Homepage: public shorten form ───────────────────────────────── */
$result = null;
$error  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    if (!cfg()['public_form'] && !isAdmin()) {
        $error = t('home.disabled');
    } else {
        $pdo = db();
        $ip  = clientIp();
        if (!isAdmin() && !rateLimitOk($pdo, $ip)) {
            $error = t('home.rate');
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
        <label class="sr-only" for="url">' . te('home.url_label') . '</label>
        <input type="url" id="url" name="url" placeholder="https://example.com/very/long/url" required maxlength="2048" autofocus>
        <button type="submit" class="btn">' . te('btn.shorten') . '</button>
    </form>';
} else {
    $formHtml = '<p class="notice">' . te('home.private') . ' <a href="/admin.php">' . te('nav.admin') . '</a></p>';
}

$resultHtml = '';
if ($result) {
    $resultHtml = '
    <div class="result" role="status">
        <input type="text" value="' . e($result['short_url']) . '" readonly data-select aria-label="Short URL">
        <button type="button" class="btn" data-copy="' . e($result['short_url']) . '">' . te('btn.copy') . '</button>
    </div>
    <p class="muted small">' . te('home.points_to', ['url' => mb_strimwidth($result['url'], 0, 80, '…')]) . '</p>';
} elseif ($error) {
    $resultHtml = '<p class="alert error" role="alert">' . e($error) . '</p>';
}

renderLayout(t('home.title'), '
    <section class="hero">
        <h1>' . te('home.h1_a') . '<br>' . te('home.h1_b') . '<span class="accent">.</span></h1>
        <p class="tagline">' . te('home.tagline') . '</p>
        ' . $formHtml . $resultHtml . '
    </section>
    <section class="features">
        <div class="card"><h2>' . te('home.f1_t') . '</h2><p class="muted">' . te('home.f1_d') . '</p></div>
        <div class="card"><h2>' . te('home.f2_t') . '</h2><p class="muted">' . te('home.f2_d') . '</p></div>
        <div class="card"><h2>' . te('home.f3_t') . '</h2><p class="muted">' . te('home.f3_d') . '</p></div>
    </section>
', [
    'nav'         => 'public',
    'index'       => true,
    'canonical'   => baseUrl() . '/',
    'description' => t('home.meta_desc'),
]);

function notFoundBody(string $msg): string {
    return '<section class="hero"><h1>404</h1><p class="tagline">' . e($msg) . '</p><p><a class="btn" href="/">' . te('nf.back') . '</a></p></section>';
}
