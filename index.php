<?php
/**
 * lnks — front controller
 * Handles the homepage (shorten form) and short-code redirects.
 */
require __DIR__ . '/bootstrap.php';

$uri = trim((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
if ($uri === 'index.php') $uri = '';

/* ── Redirect by short code ──────────────────────────────────────── */
if ($uri !== '' && isValidCode($uri)) {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method !== 'GET' && $method !== 'HEAD') {
        header('Allow: GET, HEAD');
        http_response_code(405);
        exit;
    }

    $pdo = db();
    $link = row($pdo, 'SELECT id, url, status, clicks_total, expires_at, max_clicks FROM links WHERE code = :c LIMIT 1', [':c' => $uri]);

    header('X-Robots-Tag: noindex');

    if (!$link || (int)$link['status'] !== 1) {
        http_response_code(404);
        renderLayout(t('nf.title'), notFoundBody(t('nf.link')), ['nav' => 'public']);
        exit;
    }

    // Expired or out of clicks → 410 Gone. The click limit is enforced atomically in recordClick().
    $state = linkState($link);
    if ($state === 'active' && !isBotRequest() && !recordClick($pdo, (int)$link['id'])) {
        $state = 'limit';
    }
    if ($state !== 'active') {
        http_response_code(410);
        header('Cache-Control: no-store');
        $msg = $state === 'expired' ? t('gone.expired', ['date' => substr($link['expires_at'], 0, 16)]) : t('gone.limit');
        renderLayout(t('gone.title'), notFoundBody($msg, t('gone.title')), ['nav' => 'public']);
        exit;
    }

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
        <input type="url" id="url" name="url" placeholder="' . te('home.placeholder') . '" required maxlength="2048" autofocus>
        <button type="submit" class="btn">' . te('btn.shorten') . '</button>
    </form>';
} else {
    $formHtml = '<p class="notice">' . te('home.private') . '</p>';
}

$resultHtml = '';
if ($result) {
    $resultHtml = '
    <div class="result" role="status">
        <input type="text" value="' . e($result['short_url']) . '" readonly data-select aria-label="Short URL">
        <button type="button" class="btn" data-copy="' . e($result['short_url']) . '">' . te('btn.copy') . '</button>
        ' . qrButton($result['short_url'], $result['code'], 'btn ghost') . '
    </div>
    <p class="muted small">' . te('home.points_to', ['url' => cut($result['url'], 80, '…')]) . '</p>';
} elseif ($error) {
    $resultHtml = '<p class="alert error" role="alert">' . e($error) . '</p>';
}

renderLayout(t('home.title'), '
    <section class="hero">
        <h1>' . te('home.h1_a') . '<br>' . te('home.h1_b') . '<span class="accent">.</span></h1>
        <p class="tagline">' . te('home.tagline') . '</p>
        ' . $formHtml . $resultHtml . '
    </section>
    <section class="how" aria-labelledby="how-title">
        <h2 id="how-title">' . te('home.how') . '</h2>
        <ol class="steps">
            <li class="card"><span class="step-n" aria-hidden="true">1</span><h3>' . te('home.s1_t') . '</h3><p class="muted">' . te('home.s1_d') . '</p></li>
            <li class="card"><span class="step-n" aria-hidden="true">2</span><h3>' . te('home.s2_t') . '</h3><p class="muted">' . te('home.s2_d') . '</p></li>
            <li class="card"><span class="step-n" aria-hidden="true">3</span><h3>' . te('home.s3_t') . '</h3><p class="muted">' . te('home.s3_d') . '</p></li>
        </ol>
    </section>
', [
    'nav'         => 'public',
    'index'       => true,
    'canonical'   => baseUrl() . '/',
    'description' => t('home.meta_desc'),
    'scripts'     => $result ? [QR_SCRIPT] : [],
]);

function notFoundBody(string $msg, string $heading = '404'): string {
    return '<section class="hero"><h1>' . e($heading) . '</h1><p class="tagline">' . e($msg) . '</p><p><a class="btn" href="/">' . te('nf.back') . '</a></p></section>';
}
