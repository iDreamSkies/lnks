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
    // Always check both fields (no early exit on a wrong username) so timing does not reveal which one failed.
    $userOk = hash_equals((string)$cfg['admin_user'], trim((string)($_POST['username'] ?? '')));
    $passOk = $cfg['admin_pass_hash'] !== '' && password_verify((string)$_POST['password'], $cfg['admin_pass_hash']);
    if ($cfg['admin_pass_hash'] === '') {
        $loginError = t('login.no_pass');
    } elseif (loginBlocked($pdo, $ip)) {
        $loginError = t('login.blocked');
    } elseif ($userOk && $passOk) {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        $pdo->prepare('DELETE FROM login_attempts WHERE ip = :ip')->execute([':ip' => $ip]);
        header('Location: /admin.php');
        exit;
    } else {
        loginFailed($pdo, $ip);
        $loginError = t('login.wrong');
        usleep(400000);
    }
}

if (!isAdmin()) {
    renderLayout(t('login.title'), '
        <section class="hero">
            <h1>' . te('login.title') . '</h1>
            ' . ($loginError ? '<p class="alert error" role="alert">' . e($loginError) . '</p>' : '') . '
            <form method="post" class="login">
                ' . csrfField() . '
                <label class="sr-only" for="username">' . te('login.username') . '</label>
                <input type="text" id="username" name="username" placeholder="' . te('login.username') . '" value="' . e((string)($_POST['username'] ?? '')) . '" required autofocus autocomplete="username" maxlength="64">
                <label class="sr-only" for="password">' . te('login.password') . '</label>
                <input type="password" id="password" name="password" placeholder="' . te('login.password') . '" required autocomplete="current-password">
                <button type="submit" class="btn">' . te('login.submit') . '</button>
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

/** Badge for a link state, or '' for active links (keeps the list calm). */
function stateBadge(string $state, bool $showActive = false): string {
    $map = ['active' => ['on', 'status.active'], 'disabled' => ['off', 'status.disabled'],
            'expired' => ['warn', 'status.expired'], 'limit' => ['warn', 'status.limit']];
    if ($state === 'active' && !$showActive) return '';
    return '<span class="badge ' . $map[$state][0] . '">' . te($map[$state][1]) . '</span>';
}

/** "3 d left" / "5 h left" / "12 min left" for a future UTC timestamp. */
function timeLeft(string $utc): string {
    $s = strtotime($utc . ' UTC') - time();
    if ($s >= 86400) return t('left.d', ['n' => (int)floor($s / 86400)]);
    if ($s >= 3600)  return t('left.h', ['n' => (int)floor($s / 3600)]);
    return t('left.m', ['n' => max(1, (int)ceil($s / 60))]);
}

/** Small "expires / clicks of max" line under a link. */
function limitsLine(array $l): string {
    $parts = [];
    if (!empty($l['expires_at'])) {
        $parts[] = $l['expires_at'] <= now()
            ? te('expired.on', ['date' => substr($l['expires_at'], 0, 16)])
            : '<span title="' . e($l['expires_at']) . ' UTC">' . e(timeLeft($l['expires_at'])) . '</span>';
    }
    if ($l['max_clicks'] !== null) {
        $parts[] = te('clicks.of', ['n' => (int)$l['clicks_total'], 'max' => (int)$l['max_clicks']]);
    }
    return $parts ? '<div class="muted small">' . implode(' · ', $parts) . '</div>' : '';
}

/** datetime-local value for a stored UTC timestamp. */
function dtLocal(?string $utc): string {
    return $utc ? str_replace(' ', 'T', substr($utc, 0, 16)) : '';
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
            flash('ok', t('flash.updated'));
            break;
        case 'delete':
            $pdo->prepare('DELETE FROM links WHERE id = :i')->execute([':i' => $id]);
            flash('ok', t('flash.deleted'));
            break;
        case 'create':
            $r = createLink($pdo, (string)($_POST['url'] ?? ''), $ip, [
                'title'      => (string)($_POST['title'] ?? ''),
                'expires_at' => (string)($_POST['expires_at'] ?? ''),
                'max_clicks' => (string)($_POST['max_clicks'] ?? ''),
            ]);
            $r['ok'] ? flash('ok', t('flash.created', ['url' => $r['short_url']])) : flash('error', $r['error']);
            break;
        case 'update':
            $r = updateLink($pdo, $id, [
                'title'      => (string)($_POST['title'] ?? ''),
                'expires_at' => (string)($_POST['expires_at'] ?? ''),
                'max_clicks' => (string)($_POST['max_clicks'] ?? ''),
            ]);
            $r['ok'] ? flash('ok', t('flash.saved')) : flash('error', $r['error']);
            break;
    }
    redirectBack();
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$flashHtml = $flash
    ? '<p class="alert ' . ($flash['type'] === 'ok' ? 'ok' : 'error') . '" role="status">' . e($flash['msg']) . '</p>'
    : '';
$opts = ['nav' => 'admin', 'csrf' => csrfToken(), 'scripts' => [QR_SCRIPT]];

/* ── Per-link stats page ─────────────────────────────────────────── */
if (isset($_GET['id'])) {
    $st = $pdo->prepare('SELECT * FROM links WHERE id = :i');
    $st->execute([':i' => (int)$_GET['id']]);
    $l = $st->fetch();
    if (!$l) {
        http_response_code(404);
        renderLayout(t('nf.title'), '<p class="alert error">' . te('stats.not_found') . '</p><p><a href="/admin.php">' . te('stats.back') . '</a></p>', $opts);
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
    if ($refRows === '') $refRows = '<tr><td colspan="2" class="muted center">' . te('stats.no_clicks') . '</td></tr>';

    renderLayout(t('stats.title', ['code' => $l['code']]), '
        <p><a href="/admin.php">' . te('stats.back') . '</a></p>
        ' . $flashHtml . '
        <div class="topbar">
            <div>
                <h1><a href="' . e($short) . '" target="_blank" rel="noopener">' . e($short) . '</a></h1>
                <p class="muted break">' . ($l['title'] ? e($l['title']) . ' · ' : '') . e($l['url']) . '</p>
                ' . limitsLine($l) . '
            </div>
            <div class="row-actions">
                <button type="button" class="btn ghost sm" data-copy="' . e($short) . '">' . te('btn.copy') . '</button>
                ' . qrButton($short, $l['code']) . '
                ' . stateBadge(linkState($l), true) . '
            </div>
        </div>
        <div class="tiles">
            <div class="tile"><span class="tile-n">' . (int)$l['clicks_total'] . '</span><span class="muted">' . te('stats.total') . '</span></div>
            <div class="tile"><span class="tile-n">' . $last30 . '</span><span class="muted">' . te('stats.last30') . '</span></div>
            <div class="tile"><span class="tile-n small-n">' . e($l['last_click_at'] ?? '—') . '</span><span class="muted">' . te('stats.last_click') . '</span></div>
            <div class="tile"><span class="tile-n small-n">' . e($l['created_at']) . '</span><span class="muted">' . te('stats.created') . '</span></div>
        </div>
        <section class="card">
            <h2>' . te('stats.chart') . '</h2>
            ' . barsSvg($daily, 600, 120, 'chart', $labels) . '
            <div class="chart-axis muted small"><span>' . e(array_key_first($daily)) . '</span><span>' . e(array_key_last($daily)) . '</span></div>
        </section>
        <section class="card">
            <h2>' . te('stats.refs') . '</h2>
            <table class="plain-table"><tbody>' . $refRows . '</tbody></table>
        </section>
        <section class="card">
            <h2>' . te('stats.settings') . '</h2>
            <form method="post" class="settings">
                ' . csrfField() . '
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="' . (int)$l['id'] . '">
                <input type="hidden" name="back" value="?id=' . (int)$l['id'] . '">
                <label>' . te('form.title') . '<input type="text" name="title" value="' . e((string)$l['title']) . '" maxlength="120"></label>
                <label>' . te('form.expires') . '<input type="datetime-local" name="expires_at" value="' . e(dtLocal($l['expires_at'])) . '"></label>
                <label>' . te('form.max_clicks') . '<input type="number" name="max_clicks" min="1" max="1000000000" step="1" value="' . e((string)$l['max_clicks']) . '" placeholder="' . te('form.max_clicks_ph') . '"></label>
                <button type="submit" class="btn">' . te('form.save') . '</button>
            </form>
            <p class="muted small">' . te('stats.settings_hint') . '</p>
        </section>
    ', $opts);
    exit;
}

/* ── Dashboard numbers ───────────────────────────────────────────── */
$st = $pdo->prepare('SELECT COUNT(*) total, COALESCE(SUM(CASE WHEN ' . stateSql('active') . ' THEN 1 ELSE 0 END),0) active,
                      COALESCE(SUM(clicks_total),0) clicks FROM links');
$st->execute([':now' => now()]);
$sum = $st->fetch();
$clicksSince = function (string $since) use ($pdo): int {
    $st = $pdo->prepare('SELECT COUNT(*) FROM clicks WHERE ts >= :t');
    $st->execute([':t' => $since]);
    return (int)$st->fetchColumn();
};
$today = $clicksSince(gmdate('Y-m-d') . ' 00:00:00');
$week  = $clicksSince(gmdate('Y-m-d H:i:s', time() - 7 * 86400));

/* ── List: search / filter / sort / paging ───────────────────────── */
$q      = trim((string)($_GET['q'] ?? ''));
$status = in_array($_GET['status'] ?? '', ['active', 'expired', 'disabled'], true) ? $_GET['status'] : 'all';
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
    $conds[] = stateSql($status);
    if ($status !== 'disabled') $params[':now'] = now();
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
    $state  = linkState($l);
    $rows .= '<tr' . ($state === 'active' ? '' : ' class="disabled"') . '>
        <td data-label="' . te('th.link') . '">
            <a href="/admin.php?id=' . (int)$l['id'] . '" class="code">' . e($l['code']) . '</a>
            ' . stateBadge($state) . '
            ' . ($l['title'] ? '<div class="muted small">' . e($l['title']) . '</div>' : '') . '
            ' . limitsLine($l) . '
        </td>
        <td data-label="' . te('th.dest') . '" class="url"><a href="' . e($l['url']) . '" target="_blank" rel="noopener noreferrer" title="' . e($l['url']) . '">' . e(hostOf($l['url'])) . '</a>
            <div class="muted small ellipsis">' . e($l['url']) . '</div></td>
        <td data-label="' . te('th.clicks') . '" class="num"><span class="n">' . (int)$l['clicks_total'] . '</span>' . barsSvg($sparks[(int)$l['id']], 56, 20, 'spark', array_combine(array_keys($sparks[(int)$l['id']]), array_keys($sparks[(int)$l['id']]))) . '</td>
        <td data-label="' . te('th.last') . '" class="muted nowrap">' . e($l['last_click_at'] ?? '—') . '</td>
        <td data-label="' . te('th.created') . '" class="muted nowrap">' . e(substr($l['created_at'], 0, 10)) . '</td>
        <td class="actions">
            <form method="post">' . csrfField() . '
                <input type="hidden" name="id" value="' . (int)$l['id'] . '">
                <input type="hidden" name="back" value="' . e($back) . '">
                <button type="button" class="btn ghost sm" data-copy="' . e($short) . '">' . te('btn.copy') . '</button>
                ' . qrButton($short, $l['code']) . '
                <button name="action" value="toggle" class="btn ghost sm">' . ($active ? te('btn.disable') : te('btn.enable')) . '</button>
                <button name="action" value="delete" class="btn danger sm" data-confirm="' . te('confirm.delete') . '">' . te('btn.delete') . '</button>
            </form>
        </td>
    </tr>';
}
if ($rows === '') {
    $rows = '<tr><td colspan="6" class="muted center empty">' . ($q !== '' || $status !== 'all' ? te('empty.filtered') : te('empty.none')) . '</td></tr>';
}

// Compact pager: first, current ±2, last
$pager = '';
if ($pages > 1) {
    $show = array_unique(array_filter([1, $page - 2, $page - 1, $page, $page + 1, $page + 2, $pages], fn($p) => $p >= 1 && $p <= $pages));
    sort($show);
    $pager = '<nav class="pager" aria-label="' . te('pager.aria') . '">';
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

renderLayout(t('list.title'), '
    <div class="topbar"><h1>' . te('list.title') . '</h1></div>
    ' . $flashHtml . '

    <div class="tiles">
        <div class="tile"><span class="tile-n">' . (int)$sum['total'] . '</span><span class="muted">' . te('tile.links', ['n' => (int)$sum['active']]) . '</span></div>
        <div class="tile"><span class="tile-n">' . (int)$sum['clicks'] . '</span><span class="muted">' . te('tile.clicks') . '</span></div>
        <div class="tile"><span class="tile-n">' . $today . '</span><span class="muted">' . te('tile.today') . '</span></div>
        <div class="tile"><span class="tile-n">' . $week . '</span><span class="muted">' . te('tile.week') . '</span></div>
    </div>

    <form method="post" class="card create">
        ' . csrfField() . '
        <input type="hidden" name="action" value="create">
        <div class="shorten">
            <input type="url" name="url" placeholder="https://example.com/long/url" required maxlength="2048" aria-label="' . te('form.url_label') . '">
            <input type="text" name="title" placeholder="' . te('form.title_ph') . '" maxlength="120" aria-label="' . te('form.title_ph') . '" class="title-in">
            <button type="submit" class="btn">' . te('btn.shorten') . '</button>
        </div>
        <details class="more">
            <summary>' . te('form.more') . '</summary>
            <div class="settings">
                <label>' . te('form.expires') . '<input type="datetime-local" name="expires_at"></label>
                <label>' . te('form.max_clicks') . '<input type="number" name="max_clicks" min="1" max="1000000000" step="1" placeholder="' . te('form.max_clicks_ph') . '"></label>
            </div>
        </details>
    </form>

    <form method="get" class="filters">
        <input type="search" name="q" value="' . e($q) . '" placeholder="' . te('filter.search_ph') . '" aria-label="' . te('filter.search') . '">
        <select name="status" aria-label="' . te('filter.status') . '">' . $opt('all', te('filter.all'), $status) . $opt('active', te('filter.active'), $status) . $opt('expired', te('filter.expired'), $status) . $opt('disabled', te('filter.disabled'), $status) . '</select>
        <select name="sort" aria-label="' . te('filter.sort') . '">' . $opt('new', te('sort.new'), $sort) . $opt('old', te('sort.old'), $sort) . $opt('clicks', te('sort.clicks'), $sort) . $opt('last', te('sort.last'), $sort) . '</select>
        <button type="submit" class="btn ghost">' . te('filter.apply') . '</button>
        ' . ($q !== '' || $status !== 'all' || $sort !== 'new' ? '<a href="/admin.php" class="btn ghost">' . te('filter.reset') . '</a>' : '') . '
    </form>
    <p class="muted small">' . te('list.results', ['n' => $total]) . '</p>

    <div class="table-wrap">
    <table class="links">
        <thead><tr><th>' . te('th.link') . '</th><th>' . te('th.dest') . '</th><th class="num">' . te('th.clicks') . '</th><th>' . te('th.last') . '</th><th>' . te('th.created') . '</th><th></th></tr></thead>
        <tbody>' . $rows . '</tbody>
    </table>
    </div>
    ' . $pager . supportCard() . '
', $opts);
