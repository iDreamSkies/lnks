<?php
/**
 * lnks — front controller
 * Handles the homepage (shorten form) and short-code redirects.
 */
require __DIR__ . '/bootstrap.php';

$uri = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');

/* ── Redirect by short code ──────────────────────────────────────── */
if ($uri !== '' && preg_match('~^[a-zA-Z0-9]{4,12}$~', $uri)) {
    $pdo = db();
    $st  = $pdo->prepare('SELECT id, url, status FROM links WHERE code = :c LIMIT 1');
    $st->execute([':c' => $uri]);
    $link = $st->fetch(PDO::FETCH_ASSOC);

    if (!$link || (int)$link['status'] !== 1) {
        http_response_code(404);
        header('X-Robots-Tag: noindex');
        renderPage('Not found', '<h1>404</h1><p>This short link does not exist or was disabled.</p>');
        exit;
    }

    $pdo->prepare('UPDATE links SET clicks_total = clicks_total + 1 WHERE id = :i')
        ->execute([':i' => $link['id']]);
    $pdo->prepare('INSERT INTO clicks (link_id, ts, referrer) VALUES (:l, :t, :r)')
        ->execute([':l' => $link['id'], ':t' => now(), ':r' => $_SERVER['HTTP_REFERER'] ?? null]);

    header('X-Robots-Tag: noindex');
    header('Location: ' . $link['url'], true, 302);
    exit;
}

if ($uri !== '') {
    http_response_code(404);
    renderPage('Not found', '<h1>404</h1><p>Page not found.</p>');
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
        $ip  = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!isAdmin() && !rateLimitOk($pdo, $ip)) {
            $error = 'Rate limit reached. Try again later.';
        } else {
            $r = createLink($pdo, $_POST['url'] ?? '', $ip);
            if ($r['ok']) $result = $r; else $error = $r['error'];
        }
    }
}

$formHtml = '';
if (cfg()['public_form'] || isAdmin()) {
    $formHtml = '
    <form method="post" class="shorten">
        ' . csrfField() . '
        <input type="url" name="url" placeholder="https://example.com/very/long/url" required maxlength="2048" autofocus>
        <button type="submit">Shorten</button>
    </form>';
} else {
    $formHtml = '<p class="muted">This instance is private. Use the <a href="/admin.php">admin panel</a> or the API.</p>';
}

$resultHtml = '';
if ($result) {
    $short = e($result['short_url']);
    $resultHtml = '
    <div class="result">
        <input type="text" value="' . $short . '" readonly onclick="this.select()" id="shortUrl">
        <button type="button" onclick="copyShort()">Copy</button>
    </div>';
} elseif ($error) {
    $resultHtml = '<p class="error">' . e($error) . '</p>';
}

renderPage('URL shortener', '
    <h1>lnks<span class="accent">.</span></h1>
    <p class="tagline">Minimal self-hosted URL shortener. No accounts, no tracking, no bloat.</p>
    ' . $formHtml . $resultHtml . '
    <script>
    function copyShort(){
        var i = document.getElementById("shortUrl");
        i.select();
        navigator.clipboard.writeText(i.value).then(function(){
            var b = document.querySelector(".result button");
            b.textContent = "Copied!";
            setTimeout(function(){ b.textContent = "Copy"; }, 1500);
        });
    }
    </script>
');

/* ── Layout ──────────────────────────────────────────────────────── */
function renderPage(string $title, string $body): void {
    $app = e(cfg()['app_name']);
    echo '<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>' . e($title) . ' — ' . $app . '</title>
<link rel="stylesheet" href="/public/styles.css">
</head>
<body>
<main class="container">' . $body . '</main>
<footer>
    <a href="https://github.com/iDreamSkies/lnks" rel="noopener">lnks</a> — built by
    <a href="https://dreamskies.dev" rel="noopener">DreamSkies</a>
</footer>
</body>
</html>';
}
