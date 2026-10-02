<?php
/**
 * lnks — tiny i18n layer (EN / RU).
 * Language priority: ?lang=xx (saved in a cookie) → cookie → config 'lang' (if not "auto") → Accept-Language → en.
 * Safe to include before config.php exists (the installer uses it).
 */

const LNKS_LANGS = ['en' => 'EN', 'ru' => 'RU'];

function currentLang(?string $force = null): string {
    static $lang = null;
    if ($force !== null && isset(LNKS_LANGS[$force])) return $lang = $force;   // e.g. the API is always English
    if ($lang !== null) return $lang;

    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $q = $_GET['lang'] ?? '';
    if (is_string($q) && isset(LNKS_LANGS[$q])) {
        if (!headers_sent()) {
            setcookie('lnks_lang', $q, ['expires' => time() + 31536000, 'path' => '/', 'secure' => $https, 'samesite' => 'Lax']);
        }
        return $lang = $q;
    }
    $c = $_COOKIE['lnks_lang'] ?? '';
    if (is_string($c) && isset(LNKS_LANGS[$c])) return $lang = $c;

    if (function_exists('cfg')) {
        $d = cfg()['lang'] ?? 'auto';
        if (isset(LNKS_LANGS[$d])) return $lang = $d;
    }
    if (preg_match_all('~(?<![a-z])(ru|en)(?![a-z])~i', strtolower($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''), $m) && $m[1]) {
        return $lang = $m[1][0];
    }
    return $lang = 'en';
}

/** Translate $key; {name} placeholders are replaced from $vars. Returns plain text — escape with e() / te(). */
function t(string $key, array $vars = []): string {
    static $dict = [];
    $lang = currentLang();
    foreach (['en', $lang] as $l) {
        if (!isset($dict[$l])) $dict[$l] = require __DIR__ . '/lang/' . $l . '.php';
    }
    $s = $dict[$lang][$key] ?? $dict['en'][$key] ?? $key;
    foreach ($vars as $k => $v) $s = str_replace('{' . $k . '}', (string)$v, $s);
    return $s;
}

function te(string $key, array $vars = []): string {
    return e(t($key, $vars));
}

/** Current URL with ?lang=$l, keeping other query params. */
function langUrl(string $l): string {
    $path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $qs   = $_GET;
    $qs['lang'] = $l;
    return ($path === '' ? '/' : $path) . '?' . http_build_query($qs);
}
