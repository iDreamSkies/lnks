<?php
/**
 * lnks — admin panel (single file)
 * Login, link list with click counts, disable/enable, delete.
 */
require __DIR__ . '/bootstrap.php';
header('X-Robots-Tag: noindex,nofollow');

$cfg = cfg();

/* ── Login / logout ──────────────────────────────────────────────── */
if (isset($_GET['logout'])) {
    unset($_SESSION['admin']);
    header('Location: /admin.php');
    exit;
}

$loginError = null;
if (!isAdmin() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    csrfCheck();
    if ($cfg['admin_pass_hash'] === '') {
        $loginError = 'Admin password is not configured. Set admin_pass_hash in config.php.';
    } elseif (password_verify($_POST['password'], $cfg['admin_pass_hash'])) {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        header('Location: /admin.php');
        exit;
    } else {
        $loginError = 'Wrong password.';
        usleep(500000); // slow down brute force
    }
}

if (!isAdmin()) {
    adminLayout('Login', '
        <h1>Admin login</h1>
        ' . ($loginError ? '<p class="error">' . e($loginError) . '</p>' : '') . '
        <form method="post" class="login">
            ' . csrfField() . '
            <input type="password" name="password" placeholder="Password" required autofocus>
            <button type="submit">Sign in</button>
        </form>
    ', true);
    exit;
}

/* ── Actions ─────────────────────────────────────────────────────── */
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrfCheck();
    $id = (int)($_POST['id'] ?? 0);
    switch ($_POST['action']) {
        case 'toggle':
            $pdo->prepare('UPDATE links SET status = 1 - status WHERE id = :i')->execute([':i' => $id]);
            break;
        case 'delete':
            $pdo->prepare('DELETE FROM links WHERE id = :i')->execute([':i' => $id]);
            break;
        case 'create':
            createLink($pdo, $_POST['url'] ?? '', $_SERVER['REMOTE_ADDR'] ?? null);
            break;
    }
    header('Location: /admin.php');
    exit;
}

/* ── List ────────────────────────────────────────────────────────── */
$q     = trim($_GET['q'] ?? '');
$page  = max(1, (int)($_GET['page'] ?? 1));
$per   = 50;

$where  = '';
$params = [];
if ($q !== '') {
    $where = 'WHERE code LIKE :q OR url LIKE :q';
    $params[':q'] = '%' . $q . '%';
}

$total = (function() use ($pdo, $where, $params) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM links $where");
    $st->execute($params);
    return (int)$st->fetchColumn();
})();

$st = $pdo->prepare("SELECT * FROM links $where ORDER BY id DESC LIMIT :lim OFFSET :off");
foreach ($params as $k => $v) $st->bindValue($k, $v);
$st->bindValue(':lim', $per, PDO::PARAM_INT);
$st->bindValue(':off', ($page - 1) * $per, PDO::PARAM_INT);
$st->execute();
$links = $st->fetchAll(PDO::FETCH_ASSOC);

$rows = '';
foreach ($links as $l) {
    $short  = baseUrl() . '/' . $l['code'];
    $active = (int)$l['status'] === 1;
    $rows .= '<tr' . ($active ? '' : ' class="disabled"') . '>
        <td><a href="' . e($short) . '" target="_blank" rel="noopener">' . e($l['code']) . '</a></td>
        <td class="url" title="' . e($l['url']) . '">' . e($l['url']) . '</td>
        <td class="num">' . (int)$l['clicks_total'] . '</td>
        <td class="muted">' . e($l['created_at']) . '</td>
        <td class="actions">
            <form method="post">' . csrfField() . '
                <input type="hidden" name="id" value="' . (int)$l['id'] . '">
                <button type="button" class="copy" data-url="' . e($short) . '">Copy</button>
                <button name="action" value="toggle" title="Enable/disable">' . ($active ? 'Disable' : 'Enable') . '</button>
                <button name="action" value="delete" class="danger" onclick="return confirm(\'Delete this link?\')">Delete</button>
            </form>
        </td>
    </tr>';
}
if ($rows === '') {
    $rows = '<tr><td colspan="5" class="muted center">No links yet.</td></tr>';
}

$pages = (int)ceil($total / $per);
$pager = '';
if ($pages > 1) {
    $pager = '<div class="pager">';
    for ($p = 1; $p <= $pages; $p++) {
        $pager .= $p === $page
            ? '<span class="current">' . $p . '</span>'
            : '<a href="?page=' . $p . ($q !== '' ? '&q=' . urlencode($q) : '') . '">' . $p . '</a>';
    }
    $pager .= '</div>';
}

adminLayout('Links', '
    <div class="topbar">
        <h1>Links <span class="muted">(' . $total . ')</span></h1>
        <a href="?logout=1" class="muted">Log out</a>
    </div>

    <form method="post" class="shorten">
        ' . csrfField() . '
        <input type="hidden" name="action" value="create">
        <input type="url" name="url" placeholder="https://example.com/long/url" required maxlength="2048">
        <button type="submit">Shorten</button>
    </form>

    <form method="get" class="search">
        <input type="text" name="q" value="' . e($q) . '" placeholder="Search code or URL…">
        <button type="submit">Search</button>
    </form>

    <table>
        <thead><tr><th>Code</th><th>URL</th><th>Clicks</th><th>Created (UTC)</th><th></th></tr></thead>
        <tbody>' . $rows . '</tbody>
    </table>
    ' . $pager . '
    <script>
    document.addEventListener("click", function (ev) {
        var btn = ev.target.closest("button.copy");
        if (!btn) return;
        var url = btn.dataset.url;
        function done() {
            var t = btn.textContent;
            btn.textContent = "Copied!";
            setTimeout(function () { btn.textContent = t; }, 1200);
        }
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(url).then(done);
        } else {
            // Fallback for non-HTTPS contexts
            var ta = document.createElement("textarea");
            ta.value = url;
            ta.style.position = "fixed";
            ta.style.opacity = "0";
            document.body.appendChild(ta);
            ta.select();
            document.execCommand("copy");
            document.body.removeChild(ta);
            done();
        }
    });
    </script>
');

/* ── Layout ──────────────────────────────────────────────────────── */
function adminLayout(string $title, string $body, bool $narrow = false): void {
    echo '<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>' . e($title) . ' — lnks admin</title>
<link rel="stylesheet" href="/public/styles.css">
</head>
<body>
<main class="container' . ($narrow ? '' : ' wide') . '">' . $body . '</main>
<footer>
    <a href="https://github.com/iDreamSkies/lnks" rel="noopener">lnks</a> — built by
    <a href="https://dreamskies.dev" rel="noopener">DreamSkies</a>
    · <a href="https://github.com/iDreamSkies/lnks#need-more" rel="noopener">need more features?</a>
</footer>
</body>
</html>';
}
