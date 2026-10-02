<?php
/**
 * lnks — tiny User-Agent parser (browser, OS, device type). No external data or services.
 * Deliberately coarse: families only, no versions. Order of the checks matters
 * (Edge and Opera also say "Chrome", Chrome also says "Safari").
 */

/** @return array{browser: string, os: string, device: string} device: desktop | mobile | tablet */
function parseUserAgent(string $ua): array {
    $browsers = [
        'Edge'             => '~Edg(e|A|iOS)?/~',
        'Opera'            => '~OPR/|Opera|OPiOS/~',
        'Yandex Browser'   => '~YaBrowser/~',
        'Samsung Internet' => '~SamsungBrowser/~',
        'Vivaldi'          => '~Vivaldi/~',
        'Firefox'          => '~Firefox/|FxiOS/~',
        'Chrome'           => '~Chrome/|CriOS/~',
        'Safari'           => '~Version/[\d.]+.*Safari/~',
    ];
    $oses = [
        'Windows'  => '~Windows~',
        'Android'  => '~Android~',
        'iOS'      => '~iPhone|iPad|iPod~',
        'ChromeOS' => '~CrOS~',
        'macOS'    => '~Mac OS X|Macintosh~',
        'Linux'    => '~Linux~',
    ];
    $browser = 'Other';
    foreach ($browsers as $name => $re) {
        if (preg_match($re, $ua)) { $browser = $name; break; }
    }
    $os = 'Other';
    foreach ($oses as $name => $re) {
        if (preg_match($re, $ua)) { $os = $name; break; }
    }
    if (preg_match('~iPad|Tablet|Kindle|Silk/|PlayBook~i', $ua) || ($os === 'Android' && !preg_match('~Mobile~', $ua))) {
        $device = 'tablet';
    } elseif (preg_match('~Mobi|iPhone|iPod|Windows Phone|Opera Mini~i', $ua)) {
        $device = 'mobile';
    } else {
        $device = 'desktop';
    }
    return ['browser' => $browser, 'os' => $os, 'device' => $device];
}
