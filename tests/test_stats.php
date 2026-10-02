<?php
/* Extended statistics: User-Agent parsing, optional GeoIP table, periods and breakdowns. */

const UA_IPHONE  = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
const UA_WIN_EDGE = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36 Edg/129.0.0.0';

test('stats: User-Agent families', function () {
    app();
    $cases = [
        [UA_IPHONE, 'Safari', 'iOS', 'mobile'],
        [UA_WIN_EDGE, 'Edge', 'Windows', 'desktop'],
        ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36', 'Chrome', 'Windows', 'desktop'],
        ['Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36', 'Chrome', 'Android', 'mobile'],
        ['Mozilla/5.0 (Linux; Android 13; SM-X700) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36', 'Chrome', 'Android', 'tablet'],
        ['Mozilla/5.0 (iPad; CPU OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1', 'Safari', 'iOS', 'tablet'],
        ['Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15', 'Safari', 'macOS', 'desktop'],
        ['Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:131.0) Gecko/20100101 Firefox/131.0', 'Firefox', 'Linux', 'desktop'],
        ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 YaBrowser/24.10.0.0 Safari/537.36', 'Yandex Browser', 'Windows', 'desktop'],
        ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36 OPR/114.0.0.0', 'Opera', 'Windows', 'desktop'],
        ['Mozilla/5.0 (Linux; Android 14; SAMSUNG SM-S921B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/26.0 Chrome/122.0.0.0 Mobile Safari/537.36', 'Samsung Internet', 'Android', 'mobile'],
        ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/129.0.6668.69 Mobile/15E148 Safari/604.1', 'Chrome', 'iOS', 'mobile'],
        ['Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36', 'Chrome', 'ChromeOS', 'desktop'],
        ['something odd', 'Other', 'Other', 'desktop'],
    ];
    foreach ($cases as [$ua, $b, $o, $d]) {
        eq(['browser' => $b, 'os' => $o, 'device' => $d], parseUserAgent($ua), substr($ua, 0, 60));
    }
});

test('stats: GeoIP table is built from RIR data and looked up by binary search', function () {
    app();
    $file = sys_get_temp_dir() . '/lnks-geo-' . bin2hex(random_bytes(4)) . '.bin';
    $n = geoBuild(file(__DIR__ . '/fixtures/rir-delegated.txt'), $file);
    eq(0, filesize($file) % GEO_RECORD, 'fixed-size records');
    ok($n < 10, 'adjacent NL blocks merged, gaps compressed');
    eq('NL', geoCountry('193.0.0.1', $file));
    eq('NL', geoCountry('193.0.15.255', $file), 'second, merged NL block');
    eq(null, geoCountry('193.0.16.0', $file), 'gap after the block');
    eq('RU', geoCountry('5.8.255.255', $file));
    eq('US', geoCountry('8.8.8.8', $file));
    eq(null, geoCountry('9.0.0.1', $file), 'reserved/ZZ ignored');
    eq(null, geoCountry('1.1.1.1', $file), 'unknown');
    eq(null, geoCountry('255.255.255.255', $file));
    eq(null, geoCountry('0.0.0.0', $file));
    eq(null, geoCountry('2001:db8::1', $file), 'IPv6 not resolved');
    eq(null, geoCountry('1.2.3.4', '/nonexistent'), 'missing file is harmless');
    @unlink($file);
});

test('stats: clicks record browser, OS, device — and country when GeoIP is on', function () {
    app();
    $l = api('POST', '/api.php', ['url' => 'https://example.com/ua'])->json();
    $c = new Client();
    $c->headers = ['User-Agent: ' . UA_IPHONE];
    eq(302, $c->get('/' . $l['code'])->status);
    $row = row(db(), 'SELECT browser, os, device, country FROM clicks WHERE link_id = :l', [':l' => $l['id']]);
    eq(['browser' => 'Safari', 'os' => 'iOS', 'device' => 'mobile', 'country' => null], $row, 'GeoIP off by default');

    // Turn GeoIP on with a table that maps the test client (127.0.0.1) to DE
    geoBuild(file(__DIR__ . '/fixtures/rir-delegated.txt'), instance()->dir . '/storage/geoip-v4.bin');
    writeConfig(instance()->dir, ['geoip' => true]);
    try {
        $c->headers = ['User-Agent: ' . UA_WIN_EDGE];
        eq(302, $c->get('/' . $l['code'])->status);
        $row = row(db(), 'SELECT browser, os, device, country FROM clicks WHERE link_id = :l ORDER BY id DESC LIMIT 1', [':l' => $l['id']]);
        eq(['browser' => 'Edge', 'os' => 'Windows', 'device' => 'desktop', 'country' => 'DE'], $row);
    } finally {
        writeConfig(instance()->dir);
        @unlink(instance()->dir . '/storage/geoip-v4.bin');
    }
});

test('stats: link page shows period comparison, hourly chart and breakdowns', function () {
    app();
    $pdo = db();
    $l = api('POST', '/api.php', ['url' => 'https://example.com/periods'])->json();
    $ins = $pdo->prepare('INSERT INTO clicks (link_id, ts, browser, os, device) VALUES (?, ?, ?, ?, ?)');
    // 3 clicks in the last 7 days (one at 13:xx UTC today), 2 in the 7 days before
    foreach ([[0, 'Chrome', 'Android', 'mobile'], [1, 'Chrome', 'Windows', 'desktop'], [2, 'Firefox', 'Linux', 'desktop'], [8, null, null, null], [10, 'Safari', 'iOS', 'mobile']] as [$ago, $b, $o, $d]) {
        $ins->execute([$l['id'], gmdate('Y-m-d', time() - $ago * 86400) . ' 13:15:00', $b, $o, $d]);
    }
    $s = linkStats($pdo, (int)$l['id'], 7);
    eq(3, $s['clicks']);
    eq(2, $s['previous']);
    eq(50, percentChange($s['clicks'], $s['previous']));
    eq(3, $s['hourly'][13]);
    eq(['Chrome' => 2, 'Firefox' => 1], $s['browsers']);
    eq(['desktop' => 2, 'mobile' => 1], $s['devices']);

    $page = (new Client())->login()->get('/admin.php?id=' . $l['id'] . '&days=7');
    contains('+50% vs previous 7 days', $page->body);
    contains('Clicks by hour (UTC)', $page->body);
    contains('<td>Phone</td>', $page->body);
    contains('<td>Firefox</td>', $page->body);
    contains('Country statistics are off', $page->body);
    contains('aria-current="true">7 days', $page->body);

    $all = (new Client())->login()->get('/admin.php?id=' . $l['id'] . '&days=30')->body;
    contains('<td>Unknown</td>', $all, 'clicks without UA data are grouped as unknown');

    $api = api('GET', '/api.php?code=' . $l['code'] . '&days=7')->json()['link']['stats'];
    eq(7, $api['days']);
    eq(3, $api['clicks']);
    eq(24, count($api['hourly']));
    $devices = $api['devices'];
    ksort($devices);
    eq(['desktop' => 2, 'mobile' => 1], $devices);
    eq(30, api('GET', '/api.php?code=' . $l['code'] . '&days=999')->json()['link']['stats']['days'], 'bad period falls back to 30');
});
