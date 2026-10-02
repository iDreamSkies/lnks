<?php
/**
 * lnks — shared layout, escaping helper and hard-coded support links.
 * Safe to include before config.php exists (used by the installer).
 */

const LNKS_VERSION = '1.2.0';

require_once __DIR__ . '/i18n.php';

function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/** UTF-8 safe truncation without requiring mbstring. */
function cut(string $s, int $max, string $ellipsis = ''): string {
    if (preg_match('~^.{0,' . $max . '}$~su', $s)) return $s;
    preg_match('~^.{0,' . max(0, $max - strlen($ellipsis ? '.' : '')) . '}~su', $s, $m);
    return ($m[0] ?? substr($s, 0, $max)) . $ellipsis;
}

/**
 * Support / contact links. Hard-coded on purpose: they are rendered in the
 * footer of every page and in the admin "Help & support" card.
 */
function supportLinks(): array {
    $urls = [
        'telegram' => 'https://t.me/dreamskies',
        'website'  => 'https://dreamskies.dev',
        'issues'   => 'https://github.com/iDreamSkies/lnks/issues',
        'github'   => 'https://github.com/iDreamSkies/lnks',
        'crypto'   => 'https://pay.oxapay.com/14606636/',
        'yoomoney' => 'https://yoomoney.ru/fundraise/1KGTK8NPPQN.260925',
        'full'     => 'https://github.com/iDreamSkies/lnks#need-more',
    ];
    $out = [];
    foreach ($urls as $k => $url) {
        $out[$k] = ['label' => t('support.' . $k), 'url' => $url, 'hint' => t('support.' . $k . '.hint')];
    }
    return $out;
}

function supportCard(): string {
    $items = '';
    foreach (supportLinks() as $l) {
        $items .= '<li><a href="' . e($l['url']) . '" target="_blank" rel="noopener">' . e($l['label']) . '</a>'
            . '<span class="muted"> — ' . e($l['hint']) . '</span></li>';
    }
    return '<section class="card support"><h2>' . te('support.title') . '</h2><ul class="plain">' . $items . '</ul></section>';
}

/** EN | RU switcher */
function langSwitcher(): string {
    $cur = currentLang();
    $out = '<span class="lang" role="group" aria-label="' . te('lang.label') . '">';
    foreach (LNKS_LANGS as $code => $label) {
        $out .= $code === $cur
            ? '<span class="lang-cur" aria-current="true">' . e($label) . '</span>'
            : '<a href="' . e(langUrl($code)) . '" hreflang="' . $code . '" rel="nofollow">' . e($label) . '</a>';
    }
    return $out . '</span>';
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
 * $o: nav ('public'|'admin'|'none'), narrow (bool), description, canonical, index (bool), csrf (string, admin logout form),
 *     scripts (extra script URLs loaded before app.js, e.g. QR_SCRIPT)
 */
function renderLayout(string $title, string $body, array $o = []): void {
    sendSecurityHeaders();

    $app   = $o['app'] ?? (function_exists('cfg') ? cfg()['app_name'] : 'lnks');
    $nav   = $o['nav'] ?? 'public';
    $desc  = $o['description'] ?? t('meta.default_desc');
    $v     = rawurlencode(LNKS_VERSION);
    $icon  = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%23c8ff00'/%3E%3Ccircle cx='22' cy='22' r='4' fill='%230e0f10'/%3E%3C/svg%3E";
    $fullTitle = e($title) . ' — ' . e($app);
    $scripts = '';
    foreach ($o['scripts'] ?? [] as $src) {
        $scripts .= '<script src="' . e($src) . '?v=' . $v . '" defer></script>';
    }

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
        $links = '<a href="/admin.php">' . te('nav.links') . '</a>'
            . '<a href="/admin.php?view=io">' . te('nav.import') . '</a>'
            . '<a href="/">' . te('nav.public') . '</a>'
            . '<form method="post" action="/admin.php" class="inline">'
            . '<input type="hidden" name="csrf" value="' . e($o['csrf'] ?? '') . '">'
            . '<button type="submit" name="action" value="logout" class="link">' . te('nav.logout') . '</button></form>';
    } elseif ($nav === 'public') {
        $links = '<a href="https://github.com/iDreamSkies/lnks#api" target="_blank" rel="noopener">' . te('nav.api') . '</a>'
            . '<a href="/admin.php">' . te('nav.admin') . '</a>';
    }

    $foot = '';
    foreach (supportLinks() as $l) {
        $foot .= '<a href="' . e($l['url']) . '" target="_blank" rel="noopener">' . e($l['label']) . '</a>';
    }

    echo '<!doctype html><html lang="' . currentLang() . '"><head>' . $head . '</head>'
        . '<body' . bodyData() . '>'
        . '<header class="site-header"><div class="header-inner">'
        . '<a class="brand" href="/">' . e($app) . '<span class="accent">.</span></a>'
        . '<nav class="nav">' . $links . langSwitcher() . '</nav></div></header>'
        . '<main class="container' . (!empty($o['narrow']) ? ' narrow' : '') . '">' . $body . '</main>'
        . '<footer class="site-footer"><div class="footer-inner">'
        . '<nav class="footer-links" aria-label="' . te('footer.support') . '">' . $foot . '</nav>'
        . '<p class="muted small">lnks v' . e(LNKS_VERSION) . ' · MIT · ' . te('footer.built_by') . ' '
        . '<a href="https://dreamskies.dev" target="_blank" rel="noopener">DreamSkies</a></p>'
        . '</div></footer>'
        . $scripts
        . '<script src="/public/app.js?v=' . $v . '" defer></script>'
        . '</body></html>';
}

/** Translated labels for public/app.js, exposed as <body data-*> attributes (no inline scripts under CSP). */
function bodyData(): string {
    $keys = ['copied' => 'js.copied', 'copyfail' => 'js.copyfail', 'qr-title' => 'qr.title',
             'qr-png' => 'qr.png', 'qr-svg' => 'qr.svg', 'qr-close' => 'qr.close'];
    $out = '';
    foreach ($keys as $attr => $key) $out .= ' data-' . $attr . '="' . te($key) . '"';
    return $out;
}

/** Client-side QR generator (MIT, Kazuhiko Arase) — see public/vendor/LICENSE-qrcode-generator.txt */
const QR_SCRIPT = '/public/vendor/qrcode.js';

/** "QR" button; public/app.js opens a dialog with a preview and PNG/SVG downloads. */
function qrButton(string $url, string $name, string $class = 'btn ghost sm'): string {
    return '<button type="button" class="' . e($class) . '" data-qr="' . e($url) . '" data-qr-name="' . e($name) . '">' . te('qr.button') . '</button>';
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
