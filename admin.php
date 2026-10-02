<?php
/**
 * lnks — admin panel (single file)
 * Login, dashboard, link list (search / filter / sort), per-link stats, enable/disable, delete.
 */
require __DIR__ . '/bootstrap.php';
header('X-Robots-Tag: noindex,nofollow');
header('Cache-Control: no-store');

$cfg = cfg();
$pdo = db();
$ip  = clientIp();

/* ── Login ───────────────────────────────────────────────────────── */
$loginError = null;
if (!isAdmin() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    csrfCheck();
    if ($cfg['admin_pass_hash'] === '') {
        $loginError = 'Admin password is not configured. Set admin_pass_hash in config.php.';
    } elseif (loginBlocked($pdo, $ip)) {
        $loginError = 'Too many failed attempts. Try again in 15 minutes.';
    } elseif (password_verify((string)$_POST['password'], $cfg['admin_pass_hash'])) {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        $pdo->prepare('DELETE FROM login_attempts WHERE ip = :ip')->execute([':ip' => $ip]);
        header('Location: /admin.php');
        exit;
    } else {
        loginFailed($pdo, $ip);
        $loginError = 'Wrong password.';
        usleep(400000);
    }
}

if (!isAdmin()) {
    renderLayout('Admin login', '
        <section class="hero">
            <h1>Admin login</h1>
            ' . ($loginError ? '<p class="alert error" role="alert">' . e($loginError) . '</p>' : '') . '
            <form method="post" class="login">
                ' . csrfField() . '
                <label class="sr-only" for="password">Password</label>
                <input type="password" id="password" name="password" placeholder="Password" required autofocus autocomplete="current-password">
                <button type="submit" class="btn">Sign in</button>
            </form>
        </section>
    ', ['nav' => 'public', 'narrow' => true]);
    exit;
}

/* ── Helpers ─────────────────────────────────────────────────────── */
function flash(string $type, string $msg): void { $_SESSION['flash'] = ['type' => $type, 'msg' => $msg]; }

function redirectBack(): void {
    $back = (string)($_POST['back'] ?? '');
    $qs   = preg_match('~^\?[A-Za-z0-9_=&%.+-]*$~', $back) ? $back : '';
    header('Location: /admin.php' . $qs);
    exit;
}

function adminUrl(array $params): string {
    $params = array_filter($params, fn($v) => $v !== '' && $v !== null && $v !== 'all' && $v !== 'new' && $v !== 1);
    return '/admin.php' . ($params ? '?' . http_build_query($params) : '');
}

/* ── Actions ─────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrfCheck();
    $id = (int)($_POST['id'] ?? 0);
    switch ($_POST['action']) {
        case 'logout':
            $_SESSION = [];
            session_destroy();
            header('Location: /admin.php');
            exit;
        case 'toggle':
            $pdo->prepare('UPDATE links SET status = 1 - status WHERE id = :i')->execute([':i' => $id]);
            flash('ok', 'Link updated.');
            break;
        case 'delete':
            $pdo->prepare('DELETE FROM links WHERE id = :i')->execute([':i' => $id]);
            flash('ok', 'Link deleted.');
            break;
        case 'create':
            $r = createLink($pdo, (string)($_POST['url'] ?? ''), $ip, (string)($_POST['title'] ?? ''));
            $r['ok'] ? flash('ok', 'Created ' . $r['short_url']) : flash('error', $r['error']);
            break;
    }
    redirectBack();
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$flashHtml = $flash
    ? '<p class="alert ' . ($flash['type'] === 'ok' ? 'ok' : 'error') . '" role="status">' . e($flash['msg']) . '</p>'
    : '';
$opts = ['nav' => 'admin', 'csrf' => csrfToken()];

/* ── Per-link stats page ─────────────────────────────────────────── */
if (isset($_GET['id'])) {
    $st = $pdo->prepare('SELECT * FROM links WHERE id = :i');
    $st->execute([':i' => (int)$_GET['id']]);
    $l = $st->fetch();
    if (!$l) {
        http_response_code(404);
        renderLayout('Not found', '<p class="alert error">Link not found.</p><p><a href="/admin.php">← Back to links</a></p>', $opts);
        exit;
    }

    $short  = baseUrl() . '/' . $l['code'];
    $daily  = dailyClicks($pdo, [(int)$l['id']], 30);
    $labels = [];
    foreach (array_keys($daily) as $d) $labels[$d] = $d;
    $last30 = array_sum($daily);
    $refs   = topReferrers($pdo, (int)$l['id']);
    $refMax = max(1, ...array_values($refs ?: [0]));

    $refRows = '';
    foreach ($refs as $host => $c) {
        $refRows .= '<tr><td>' . e($host) . '</td><td class="num">' . $c . '</td></tr>';
    }
    if ($refRows === '') $refRows = '<tr><td colspan="2" class="muted center">No clicks yet.</td></tr>';

    renderLayout('Stats ' . $l['code'], '
        <p><a href="/admin.php">← All links</a></p>
        ' . $flashHtml . '
        <div class="topbar">
            <div>
                <h1><a href="' . e($short) . '" target="_blank" rel="noopener">' . e($short) . '</a></h1>
                <p class="muted break">' . ($l['title'] ? e($l['title']) . ' · ' : '') . e($l['url']) . '</p>
            </div>
            <div class="row-actions">
                <button type="button" class="btn ghost sm" data-copy="' . e($short) . '">Copy</button>
                <span class="badge ' . ((int)$l['status'] === 1 ? 'on' : 'off') . '">' . ((int)$l['status'] === 1 ? 'Active' : 'Disabled') . '</span>
            </div>
        </div>
        <div class="tiles">
            <div class="tile"><span class="tile-n">' . (int)$l['clicks_total'] . '</span><span class="muted">Total clicks</span></div>
            <div class="tile"><span class="tile-n">' . $last30 . '</span><span class="muted">Last 30 days</span></div>
            <div class="tile"><span class="tile-n small-n">' . e($l['last_click_at'] ?? '—') . '</span><span class="muted">Last click (UTC)</span></div>
            <div class="tile"><span class="tile-n small-n">' . e($l['created_at']) . '</span><span class="muted">Created (UTC)</span></div>
        </div>
        <section class="card">
            <h2>Clicks, last 30 days</h2>
            ' . barsSvg($daily, 600, 120, 'chart', $labels) . '
            <div class="chart-axis muted small"><span>' . e(array_key_first($daily)) . '</span><span>' . e(array_key_last($daily)) . '</span></div>
        </section>
        <section class="card">
            <h2>Top referrers</h2>
            <table class="plain-table"><tbody>' . $refRows . '</tbody></table>
        </section>
    ', $opts);
    exit;
}

/* ── Dashboard numbers ───────────────────────────────────────────── */
$sum = $pdo->query('SELECT COUNT(*) total, COALESCE(SUM(status),0) active, COALESCE(SUM(clicks_total),0) clicks FROM links')->fetch();
$clicksSince = function (string $since) use ($pdo): int {
    $st = $pdo->prepare('SELECT COUNT(*) FROM clicks WHERE ts >= :t');
    $st->execute([':t' => $since]);
    return (int)$st->fetchColumn();
};
$today = $clicksSince(gmdate('Y-m-d') . ' 00:00:00');
$week  = $clicksSince(gmdate('Y-m-d H:i:s', time() - 7 * 86400));

/* ── List: search / filter / sort / paging ───────────────────────── */
$q      = trim((string)($_GET['q'] ?? ''));
$status = in_array($_GET['status'] ?? '', ['active', 'disabled'], true) ? $_GET['status'] : 'all';
$sorts  = [
    'new'    => 'id DESC',
    'old'    => 'id ASC',
    'clicks' => 'clicks_total DESC, id DESC',
    'last'   => 'last_click_at IS NULL, last_click_at DESC, id DESC',
];
$sort   = isset($sorts[$_GET['sort'] ?? '']) ? $_GET['sort'] : 'new';
$page   = max(1, (int)($_GET['page'] ?? 1));
$per    = 25;

$conds  = [];
$params = [];
if ($q !== '') {
    $conds[] = "(code LIKE :q ESCAPE '\\' OR url LIKE :q ESCAPE '\\' OR title LIKE :q ESCAPE '\\')";
    $params[':q'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
}
if ($status !== 'all') {
    $conds[] = 'status = :s';
    $params[':s'] = $status === 'active' ? 1 : 0;
}
$where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

$st = $pdo->prepare("SELECT COUNT(*) FROM links $where");
$st->execute($params);
$total = (int)$st->fetchColumn();
$pages = max(1, (int)ceil($total / $per));
$page  = min($page, $pages);

$st = $pdo->prepare("SELECT * FROM links $where ORDER BY {$sorts[$sort]} LIMIT :lim OFFSET :off");
foreach ($params as $k => $v) $st->bindValue($k, $v);
$st->bindValue(':lim', $per, PDO::PARAM_INT);
$st->bindValue(':off', ($page - 1) * $per, PDO::PARAM_INT);
$st->execute();
$links = $st->fetchAll();

$sparks = sparkData($pdo, array_column($links, 'id'));
$filters = ['q' => $q, 'status' => $status, 'sort' => $sort];
$back    = substr(adminUrl($filters + ['page' => $page]), strlen('/admin.php'));   // "?q=..." or ""

$rows = '';
foreach ($links as $l) {
    $short  = baseUrl() . '/' . $l['code'];
    $active = (int)$l['status'] === 1;
    $rows .= '<tr' . ($active ? '' : ' class="disabled"') . '>
        <td data-label="Link">
            <a href="/admin.php?id=' . (int)$l['id'] . '" class="code">' . e($l['code']) . '</a>
            ' . ($active ? '' : '<span class="badge off">Disabled</span>') . '
            ' . ($l['title'] ? '<div class="muted small">' . e($l['title']) . '</div>' : '') . '
        </td>
        <td data-label="Destination" class="url"><a href="' . e($l['url']) . '" target="_blank" rel="noopener noreferrer" title="' . e($l['url']) . '">' . e(hostOf($l['url'])) . '</a>
            <div class="muted small ellipsis">' . e($l['url']) . '</div></td>
        <td data-label="Clicks" class="num"><span class="n">' . (int)$l['clicks_total'] . '</span>' . barsSvg($sparks[(int)$l['id']], 56, 20, 'spark', array_combine(array_keys($sparks[(int)$l['id']]), array_keys($sparks[(int)$l['id']]))) . '</td>
        <td data-label="Last click" class="muted nowrap">' . e($l['last_click_at'] ?? '—') . '</td>
        <td data-label="Created" class="muted nowrap">' . e(substr($l['created_at'], 0, 10)) . '</td>
        <td class="actions">
            <form method="post">' . csrfField() . '
                <input type="hidden" name="id" value="' . (int)$l['id'] . '">
                <input type="hidden" name="back" value="' . e($back) . '">
                <button type="button" class="btn ghost sm" data-copy="' . e($short) . '">Copy</button>
                <button name="action" value="toggle" class="btn ghost sm">' . ($active ? 'Disable' : 'Enable') . '</button>
                <button name="action" value="delete" class="btn danger sm" data-confirm="Delete this link and its click history?">Delete</button>
            </form>
        </td>
    </tr>';
}
if ($rows === '') {
    $rows = '<tr><td colspan="6" class="muted center empty">' . ($q !== '' || $status !== 'all' ? 'Nothing matches the filters.' : 'No links yet — create the first one above.') . '</td></tr>';
}

// Compact pager: first, current ±2, last
$pager = '';
if ($pages > 1) {
    $show = array_unique(array_filter([1, $page - 2, $page - 1, $page, $page + 1, $page + 2, $pages], fn($p) => $p >= 1 && $p <= $pages));
    sort($show);
    $pager = '<nav class="pager" aria-label="Pages">';
    if ($page > 1) $pager .= '<a href="' . e(adminUrl($filters + ['page' => $page - 1])) . '">←</a>';
    $prev = 0;
    foreach ($show as $p) {
        if ($p - $prev > 1) $pager .= '<span class="gap">…</span>';
        $pager .= $p === $page
            ? '<span class="current">' . $p . '</span>'
            : '<a href="' . e(adminUrl($filters + ['page' => $p])) . '">' . $p . '</a>';
        $prev = $p;
    }
    if ($page < $pages) $pager .= '<a href="' . e(adminUrl($filters + ['page' => $page + 1])) . '">→</a>';
    $pager .= '</nav>';
}

$opt = fn(string $v, string $label, string $cur) => '<option value="' . $v . '"' . ($v === $cur ? ' selected' : '') . '>' . $label . '</option>';

renderLayout('Links', '
    <div class="topbar"><h1>Links</h1></div>
    ' . $flashHtml . '

    <div class="tiles">
        <div class="tile"><span class="tile-n">' . (int)$sum['total'] . '</span><span class="muted">Links (' . (int)$sum['active'] . ' active)</span></div>
        <div class="tile"><span class="tile-n">' . (int)$sum['clicks'] . '</span><span class="muted">Total clicks</span></div>
        <div class="tile"><span class="tile-n">' . $today . '</span><span class="muted">Clicks today</span></div>
        <div class="tile"><span class="tile-n">' . $week . '</span><span class="muted">Clicks, 7 days</span></div>
    </div>

    <form method="post" class="shorten card">
        ' . csrfField() . '
        <input type="hidden" name="action" value="create">
        <input type="url" name="url" placeholder="https://example.com/long/url" required maxlength="2048" aria-label="Long URL">
        <input type="text" name="title" placeholder="Title (optional)" maxlength="120" aria-label="Title" class="title-in">
        <button type="submit" class="btn">Shorten</button>
    </form>

    <form method="get" class="filters">
        <input type="search" name="q" value="' . e($q) . '" placeholder="Search code, title or URL…" aria-label="Search">
        <select name="status" aria-label="Status">' . $opt('all', 'All', $status) . $opt('active', 'Active', $status) . $opt('disabled', 'Disabled', $status) . '</select>
        <select name="sort" aria-label="Sort">' . $opt('new', 'Newest', $sort) . $opt('old', 'Oldest', $sort) . $opt('clicks', 'Most clicks', $sort) . $opt('last', 'Last clicked', $sort) . '</select>
        <button type="submit" class="btn ghost">Apply</button>
        ' . ($q !== '' || $status !== 'all' || $sort !== 'new' ? '<a href="/admin.php" class="btn ghost">Reset</a>' : '') . '
    </form>
    <p class="muted small">' . $total . ' result' . ($total === 1 ? '' : 's') . ' · times in UTC · sparkline = last 7 days</p>

    <div class="table-wrap">
    <table class="links">
        <thead><tr><th>Link</th><th>Destination</th><th class="num">Clicks</th><th>Last click</th><th>Created</th><th></th></tr></thead>
        <tbody>' . $rows . '</tbody>
    </table>
    </div>
    ' . $pager . supportCard() . '
', $opts);
