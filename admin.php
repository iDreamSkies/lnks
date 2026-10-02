<?php
/**
 * lnks — admin panel (single file)
 * Login, dashboard, link list (search / filter / sort), per-link stats, enable/disable, delete.
 */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/csv.php';
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

/** Lock badge for password-protected links. */
function lockBadge(array $l): string {
    return empty($l['password_hash']) ? '' : '<span class="badge lock" title="' . te('status.protected') . '">' . icon('lock') . te('status.protected') . '</span>';
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

/** Parse the uploaded CSV and keep the valid rows until the admin confirms. */
function importPreview(PDO $pdo): array {
    $f = $_FILES['file'] ?? null;
    if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || $f['size'] > CSV_MAX_BYTES || !is_uploaded_file($f['tmp_name'])) {
        return ['fatal' => t('csv.err_upload')];
    }
    $parsed = parseCsv((string)file_get_contents($f['tmp_name']));
    $plan   = planImport($pdo, $parsed['rows']);
    $_SESSION['import_id'] = $plan['new'] ? savePendingImport($parsed['rows']) : null;
    return ['parsed' => $parsed, 'plan' => $plan, 'id' => $_SESSION['import_id']];
}

/* ── CSV export ──────────────────────────────────────────────────── */
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . exportFilename() . '"');
    exportCsv($pdo, fopen('php://output', 'w'));
    exit;
}

/* ── Actions ─────────────────────────────────────────────────────── */
$preview = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_preview') {
    csrfCheck();
    $preview = importPreview($pdo);   // rendered on the import page below, no redirect
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
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
            [$utm, $err] = resolveUtm($pdo, (string)($_POST['utm_template'] ?? ''), $_POST);
            if ($err) {
                flash('error', $err);
                break;
            }
            $r = createLink($pdo, (string)($_POST['url'] ?? ''), $ip, [
                'utm'        => $utm,
                'title'      => (string)($_POST['title'] ?? ''),
                'expires_at' => (string)($_POST['expires_at'] ?? ''),
                'max_clicks' => (string)($_POST['max_clicks'] ?? ''),
                'password'   => (string)($_POST['password'] ?? ''),
            ]);
            $r['ok'] ? flash('ok', t('flash.created', ['url' => $r['short_url']])) : flash('error', $r['error']);
            break;
        case 'update':
            $fields = [
                'title'      => (string)($_POST['title'] ?? ''),
                'expires_at' => (string)($_POST['expires_at'] ?? ''),
                'max_clicks' => (string)($_POST['max_clicks'] ?? ''),
            ];
            // Password: empty field keeps the current one; the checkbox removes it
            if (!empty($_POST['password_remove'])) $fields['password'] = null;
            elseif ((string)($_POST['password'] ?? '') !== '') $fields['password'] = (string)$_POST['password'];
            $r = updateLink($pdo, $id, $fields);
            $r['ok'] ? flash('ok', t('flash.saved')) : flash('error', $r['error']);
            break;
        case 'utm_add':
            $r = saveUtmTemplate($pdo, (string)($_POST['name'] ?? ''), $_POST);
            $r['ok'] ? flash('ok', t('utm.saved')) : flash('error', $r['error']);
            break;
        case 'utm_delete':
            $pdo->prepare('DELETE FROM utm_templates WHERE id = :i')->execute([':i' => $id]);
            flash('ok', t('utm.deleted'));
            break;
        case 'import_run':
            $importId = (string)($_POST['import_id'] ?? '');
            $rows = hash_equals((string)($_SESSION['import_id'] ?? ''), $importId) ? takePendingImport($importId) : null;
            unset($_SESSION['import_id']);
            if ($rows === null) {
                flash('error', t('csv.expired'));
                header('Location: /admin.php?view=io');
                exit;
            }
            $r = runImport($pdo, $rows);
            flash('ok', t('csv.done', ['n' => $r['imported'], 'dup' => $r['duplicates']]));
            header('Location: /admin.php');
            exit;
    }
    redirectBack();
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$flashHtml = $flash
    ? '<p class="alert ' . ($flash['type'] === 'ok' ? 'ok' : 'error') . '" role="status">' . e($flash['msg']) . '</p>'
    : '';
$opts = ['nav' => 'admin', 'csrf' => csrfToken(), 'scripts' => [QR_SCRIPT]];

/** UTM inputs (+ template picker) shared by the create form. */
function utmFields(array $templates): string {
    $opts = '<option value="">' . te('utm.none') . '</option>';
    foreach ($templates as $tpl) {
        $data = json_encode(['source' => (string)$tpl['source'], 'medium' => (string)$tpl['medium'], 'campaign' => (string)$tpl['campaign']], JSON_UNESCAPED_UNICODE);
        $opts .= '<option value="' . (int)$tpl['id'] . '" data-utm="' . e($data) . '">' . e($tpl['name']) . '</option>';
    }
    return '<fieldset class="utm">
            <legend>' . te('utm.h') . '</legend>
            <div class="settings">
                ' . ($templates ? '<label>' . te('utm.template') . '<select name="utm_template" data-utm-select>' . $opts . '</select></label>' : '') . '
                ' . utmInputs() . '
            </div>
        </fieldset>';
}

function utmInputs(array $values = []): string {
    $lists = ['source' => ['google', 'yandex', 'telegram', 'vk', 'facebook', 'instagram', 'youtube', 'newsletter'],
              'medium' => ['cpc', 'social', 'email', 'referral', 'banner', 'qr', 'messenger']];
    $out = '';
    foreach (UTM_FIELDS as $f) {
        $list = isset($lists[$f]) ? ' list="utm-' . $f . '-list"' : '';
        $out .= '<label>' . te('utm.' . $f) . '<input type="text" name="utm_' . $f . '" maxlength="100" value="' . e((string)($values[$f] ?? '')) . '"' . $list . ' autocomplete="off"></label>';
    }
    foreach ($lists as $f => $items) {
        $out .= '<datalist id="utm-' . $f . '-list">' . implode('', array_map(fn($v) => '<option value="' . e($v) . '">', $items)) . '</datalist>';
    }
    return $out;
}

/* ── UTM templates page ───────────────────────────────────────────── */
if (($_GET['view'] ?? '') === 'utm') {
    $rowsHtml = '';
    foreach (utmTemplates($pdo) as $tpl) {
        $rowsHtml .= '<tr><td><strong>' . e($tpl['name']) . '</strong></td>'
            . '<td class="code">' . e((string)$tpl['source']) . '</td><td class="code">' . e((string)$tpl['medium']) . '</td><td class="code">' . e((string)$tpl['campaign']) . '</td>'
            . '<td class="actions"><form method="post" class="icon-bar">' . csrfField()
            . '<input type="hidden" name="id" value="' . (int)$tpl['id'] . '"><input type="hidden" name="back" value="?view=utm">'
            . '<button name="action" value="utm_delete" class="ibtn danger" data-confirm="' . te('utm.confirm_delete') . '" title="' . te('btn.delete') . '" aria-label="' . te('btn.delete') . '">' . icon('trash') . '</button>'
            . '</form></td></tr>';
    }
    if ($rowsHtml === '') $rowsHtml = '<tr><td colspan="5" class="muted center empty">' . te('utm.empty') . '</td></tr>';

    renderLayout(t('utm.page_title'), '
        <div class="topbar"><h1>' . te('utm.page_title') . '</h1></div>
        ' . $flashHtml . '
        <p class="muted">' . te('utm.page_text') . '</p>
        <form method="post" class="card create">
            ' . csrfField() . '
            <input type="hidden" name="action" value="utm_add">
            <input type="hidden" name="back" value="?view=utm">
            <div class="settings">
                <label>' . te('utm.name') . '<input type="text" name="name" maxlength="60" required></label>
                ' . utmInputs() . '
                <button type="submit" class="btn">' . te('utm.add') . '</button>
            </div>
        </form>
        <div class="table-wrap">
        <table class="plain-table">
            <thead><tr><th>' . te('utm.name') . '</th><th>utm_source</th><th>utm_medium</th><th>utm_campaign</th><th></th></tr></thead>
            <tbody>' . $rowsHtml . '</tbody>
        </table>
        </div>
    ', $opts);
    exit;
}

/* ── Import / export page ─────────────────────────────────────────── */
if (($_GET['view'] ?? '') === 'io' || $preview !== null) {
    $previewHtml = '';
    if ($preview !== null && isset($preview['fatal'])) {
        $previewHtml = '<p class="alert error" role="alert">' . e($preview['fatal']) . '</p>';
    } elseif ($preview !== null) {
        $p = $preview['parsed'];
        $plan = $preview['plan'];
        $cell = fn($s) => '<td class="ellipsis">' . e((string)$s) . '</td>';

        $errRows = '';
        foreach (array_slice($p['errors'], 0, 100) as $er) {
            $errRows .= '<tr><td class="num">' . (int)$er['line'] . '</td>' . $cell($er['url'] ?? '') . '<td>' . e($er['error']) . '</td></tr>';
        }
        $dupRows = '';
        foreach (array_slice($plan['duplicates'], 0, 100) as $d) {
            $dupRows .= '<tr><td class="num">' . (int)$d['line'] . '</td><td class="code">' . e($d['code']) . '</td>' . $cell($d['url'])
                . '<td>' . te($d['reason'] === 'exists' ? 'csv.reason_exists' : 'csv.reason_file') . '</td></tr>';
        }
        $newRows = '';
        foreach (array_slice($plan['new'], 0, 20) as $n) {
            $newRows .= '<tr><td class="num">' . (int)$n['line'] . '</td><td class="code">' . ($n['code'] !== '' ? e($n['code']) : '<span class="muted">' . te('csv.generated') . '</span>') . '</td>'
                . $cell($n['url']) . $cell((string)$n['title']) . '<td class="num">' . (int)$n['clicks'] . '</td></tr>';
        }
        $table = fn(string $h, string $head, string $rows) => $rows === '' ? '' :
            '<section class="card"><h2>' . $h . '</h2><div class="table-wrap"><table class="plain-table wide"><thead><tr>' . $head . '</tr></thead><tbody>' . $rows . '</tbody></table></div></section>';
        $th = fn(string ...$keys) => implode('', array_map(fn($k) => '<th>' . te($k) . '</th>', $keys));

        $previewHtml = '<section class="card preview">
                <h2>' . te('csv.preview_h') . '</h2>
                <p>' . te('csv.summary', ['total' => $p['total'], 'new' => count($plan['new']), 'dup' => count($plan['duplicates']), 'err' => count($p['errors'])]) . '</p>
                ' . ($p['format'] === 'yourls' ? '<p class="alert ok">' . te('csv.format_yourls') . '</p>' : '') . '
                ' . ($preview['id']
                    ? '<form method="post" class="row-actions">' . csrfField() . '
                        <input type="hidden" name="action" value="import_run">
                        <input type="hidden" name="import_id" value="' . e($preview['id']) . '">
                        <button type="submit" class="btn">' . te('csv.run_btn', ['n' => count($plan['new'])]) . '</button>
                        <a class="btn ghost" href="/admin.php?view=io">' . te('csv.cancel') . '</a>
                      </form>'
                    : '<p class="muted">' . te('csv.nothing') . '</p>') . '
            </section>'
            . $table(te('csv.err_h'), $th('csv.th_line', 'csv.th_url', 'csv.th_reason'), $errRows)
            . $table(te('csv.dup_h'), $th('csv.th_line', 'csv.th_code', 'csv.th_url', 'csv.th_reason'), $dupRows)
            . $table(te('csv.new_h', ['n' => min(20, count($plan['new']))]), $th('csv.th_line', 'csv.th_code', 'csv.th_url', 'form.title', 'th.clicks'), $newRows);
    }

    renderLayout(t('csv.title'), '
        <div class="topbar"><h1>' . te('csv.title') . '</h1></div>
        ' . $flashHtml . '
        <div class="io-grid">
            <section class="card">
                <h2>' . te('csv.export_h') . '</h2>
                <p class="muted">' . te('csv.export_text') . '</p>
                <a class="btn" href="/admin.php?export=csv">' . te('csv.export_btn') . '</a>
            </section>
            <section class="card">
                <h2>' . te('csv.import_h') . '</h2>
                <p class="muted small">' . te('csv.import_text') . '</p>
                <form method="post" enctype="multipart/form-data" class="upload">
                    ' . csrfField() . '
                    <input type="hidden" name="action" value="import_preview">
                    <label class="sr-only" for="csvfile">' . te('csv.file') . '</label>
                    <input type="file" id="csvfile" name="file" accept=".csv,.txt,text/csv,text/plain" required>
                    <button type="submit" class="btn">' . te('csv.preview_btn') . '</button>
                </form>
            </section>
        </div>
        ' . $previewHtml . '
    ', $opts);
    exit;
}

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
                ' . stateBadge(linkState($l), true) . lockBadge($l) . '
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
            <table class="plain-table refs"><tbody>' . $refRows . '</tbody></table>
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
                <label>' . te('form.password') . '<input type="password" name="password" minlength="4" maxlength="128" autocomplete="new-password" placeholder="' . (empty($l['password_hash']) ? te('form.password_ph') : te('form.password_keep')) . '"></label>
                ' . (empty($l['password_hash']) ? '' : '<label class="checkbox"><input type="checkbox" name="password_remove" value="1"> ' . te('form.password_remove') . '</label>') . '
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
        <td data-label="' . te('th.link') . '" class="cell-link">
            <a href="/admin.php?id=' . (int)$l['id'] . '" class="code">' . e($l['code']) . '</a>
            ' . stateBadge($state) . lockBadge($l) . '
            ' . ($l['title'] ? '<div class="muted small">' . e($l['title']) . '</div>' : '') . '
            ' . limitsLine($l) . '
        </td>
        <td data-label="' . te('th.dest') . '" class="url cell-dest"><a href="' . e($l['url']) . '" target="_blank" rel="noopener noreferrer" title="' . e($l['url']) . '">' . e(hostOf($l['url'])) . '</a>
            <div class="muted small ellipsis">' . e($l['url']) . '</div></td>
        <td data-label="' . te('th.clicks') . '" class="num"><span class="n">' . (int)$l['clicks_total'] . '</span>' . barsSvg($sparks[(int)$l['id']], 56, 20, 'spark', array_combine(array_keys($sparks[(int)$l['id']]), array_keys($sparks[(int)$l['id']]))) . '</td>
        <td data-label="' . te('th.last') . '" class="muted nowrap">' . e($l['last_click_at'] ?? '—') . '</td>
        <td data-label="' . te('th.created') . '" class="muted nowrap">' . e(substr($l['created_at'], 0, 10)) . '</td>
        <td class="actions">
            <form method="post" class="icon-bar">' . csrfField() . '
                <input type="hidden" name="id" value="' . (int)$l['id'] . '">
                <input type="hidden" name="back" value="' . e($back) . '">
                <a class="ibtn" href="/admin.php?id=' . (int)$l['id'] . '" title="' . te('btn.stats') . '" aria-label="' . te('btn.stats') . '">' . icon('stats') . '</a>
                <button type="button" class="ibtn" data-copy="' . e($short) . '" title="' . te('btn.copy') . '" aria-label="' . te('btn.copy') . '">' . icon('copy') . '</button>
                ' . qrButton($short, $l['code'], 'ibtn') . '
                <button name="action" value="toggle" class="ibtn" title="' . ($active ? te('btn.disable') : te('btn.enable')) . '" aria-label="' . ($active ? te('btn.disable') : te('btn.enable')) . '">' . icon($active ? 'pause' : 'play') . '</button>
                <button name="action" value="delete" class="ibtn danger" data-confirm="' . te('confirm.delete') . '" title="' . te('btn.delete') . '" aria-label="' . te('btn.delete') . '">' . icon('trash') . '</button>
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
                <label>' . te('form.password') . '<input type="password" name="password" minlength="4" maxlength="128" autocomplete="new-password" placeholder="' . te('form.password_ph') . '"></label>
            </div>
            ' . utmFields(utmTemplates($pdo)) . '
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
