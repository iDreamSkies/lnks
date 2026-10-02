<?php
/**
 * lnks — shared layout, escaping helper and hard-coded support links.
 * Safe to include before config.php exists (used by the installer).
 */

const LNKS_VERSION = '1.1.0';

function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/**
 * Support / contact links. Hard-coded on purpose: they are rendered in the
 * footer of every page and in the admin "Help & support" card.
 */
function supportLinks(): array {
    return [
        'telegram' => ['label' => 'Telegram',        'url' => 'https://t.me/dreamskies',                          'hint' => 'Questions and quick help'],
        'website'  => ['label' => 'Website',         'url' => 'https://dreamskies.dev',                           'hint' => 'Custom development and hosting'],
        'issues'   => ['label' => 'Report an issue', 'url' => 'https://github.com/iDreamSkies/lnks/issues',      'hint' => 'Bugs and feature requests'],
        'github'   => ['label' => 'GitHub',          'url' => 'https://github.com/iDreamSkies/lnks',              'hint' => 'Source code and docs'],
        'crypto'   => ['label' => 'Donate (crypto)', 'url' => 'https://pay.oxapay.com/14606636/',                  'hint' => 'Support the project with crypto'],
        'yoomoney' => ['label' => 'Donate (RU, ЮMoney)', 'url' => 'https://yoomoney.ru/fundraise/1KGTK8NPPQN.260925', 'hint' => 'Поддержать проект (для пользователей из РФ)'],
        'full'     => ['label' => 'Full edition',    'url' => 'https://github.com/iDreamSkies/lnks#need-more',    'hint' => 'Analytics, custom slugs, teams'],
    ];
}

function supportCard(): string {
    $items = '';
    foreach (supportLinks() as $l) {
        $items .= '<li><a href="' . e($l['url']) . '" target="_blank" rel="noopener">' . e($l['label']) . '</a>'
            . '<span class="muted"> — ' . e($l['hint']) . '</span></li>';
    }
    return '<section class="card support"><h2>Help &amp; support</h2><ul class="plain">' . $items . '</ul></section>';
}

function sendSecurityHeaders(): void {
    if (headers_sent()) return;
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
}

/**
 * Render a full page.
 * $o: nav ('public'|'admin'|'none'), narrow (bool), description, canonical, index (bool), csrf (string, admin logout form)
 */
function renderLayout(string $title, string $body, array $o = []): void {
    sendSecurityHeaders();

    $app   = $o['app'] ?? (function_exists('cfg') ? cfg()['app_name'] : 'lnks');
    $nav   = $o['nav'] ?? 'public';
    $desc  = $o['description'] ?? 'Minimal self-hosted URL shortener. No accounts, no tracking.';
    $v     = rawurlencode(LNKS_VERSION);
    $icon  = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%23c8ff00'/%3E%3Ccircle cx='22' cy='22' r='4' fill='%230e0f10'/%3E%3C/svg%3E";
    $fullTitle = e($title) . ' — ' . e($app);

    $head  = '<meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="color-scheme" content="dark light">'
        . '<title>' . $fullTitle . '</title>'
        . '<meta name="description" content="' . e($desc) . '">'
        . '<meta name="robots" content="' . (!empty($o['index']) ? 'index,follow' : 'noindex,nofollow') . '">'
        . '<link rel="icon" href="' . $icon . '">'
        . '<link rel="stylesheet" href="/public/styles.css?v=' . $v . '">';
    if (!empty($o['canonical'])) {
        $head .= '<link rel="canonical" href="' . e($o['canonical']) . '">'
            . '<meta property="og:type" content="website">'
            . '<meta property="og:title" content="' . $fullTitle . '">'
            . '<meta property="og:description" content="' . e($desc) . '">'
            . '<meta property="og:url" content="' . e($o['canonical']) . '">'
            . '<meta name="twitter:card" content="summary">';
    }

    $links = '';
    if ($nav === 'admin') {
        $links = '<a href="/admin.php">Links</a>'
            . '<a href="/" >Public page</a>'
            . '<form method="post" action="/admin.php" class="inline">'
            . '<input type="hidden" name="csrf" value="' . e($o['csrf'] ?? '') . '">'
            . '<button type="submit" name="action" value="logout" class="link">Log out</button></form>';
    } elseif ($nav === 'public') {
        $links = '<a href="https://github.com/iDreamSkies/lnks#api" target="_blank" rel="noopener">API</a>'
            . '<a href="/admin.php">Admin</a>';
    }

    $foot = '';
    foreach (supportLinks() as $l) {
        $foot .= '<a href="' . e($l['url']) . '" target="_blank" rel="noopener">' . e($l['label']) . '</a>';
    }

    echo '<!doctype html><html lang="en"><head>' . $head . '</head><body>'
        . '<header class="site-header"><div class="header-inner">'
        . '<a class="brand" href="/">' . e($app) . '<span class="accent">.</span></a>'
        . '<nav class="nav">' . $links . '</nav></div></header>'
        . '<main class="container' . (!empty($o['narrow']) ? ' narrow' : '') . '">' . $body . '</main>'
        . '<footer class="site-footer"><div class="footer-inner">'
        . '<nav class="footer-links" aria-label="Support">' . $foot . '</nav>'
        . '<p class="muted small">lnks v' . e(LNKS_VERSION) . ' · MIT · built by '
        . '<a href="https://dreamskies.dev" target="_blank" rel="noopener">DreamSkies</a></p>'
        . '</div></footer>'
        . '<script src="/public/app.js?v=' . $v . '" defer></script>'
        . '</body></html>';
}

/** Inline SVG bar chart (no inline styles so the strict CSP stays intact). */
function barsSvg(array $values, int $w, int $h, string $class = 'spark', array $labels = []): string {
    $n   = max(1, count($values));
    $max = max(1, ...array_values($values ?: [0]));
    $gap = $w > 200 ? 3 : 2;
    $bw  = ($w - $gap * ($n - 1)) / $n;
    $svg = '<svg class="' . e($class) . '" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" preserveAspectRatio="none">';
    $i = 0;
    foreach ($values as $k => $v) {
        $bh = $v > 0 ? max(2, $v / $max * $h) : 1;
        $x  = $i * ($bw + $gap);
        $tip = ($labels[$k] ?? (string)$k) . ': ' . (int)$v;
        $svg .= sprintf(
            '<rect class="%s" x="%.2f" y="%.2f" width="%.2f" height="%.2f" rx="1"><title>%s</title></rect>',
            $v > 0 ? 'bar' : 'bar zero', $x, $h - $bh, $bw, $bh, e($tip)
        );
        $i++;
    }
    return $svg . '</svg>';
}
