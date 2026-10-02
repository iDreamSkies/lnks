<?php
/* Link expiry (expires_at) and click limits (max_clicks). */

test('expiry: parseExpiry accepts UTC formats and rejects bad or past dates', function () {
    app();
    $y = (int)gmdate('Y') + 1;
    eq(["$y-05-01 12:30:00", null], parseExpiry("$y-05-01 12:30"));
    eq(["$y-05-01 12:30:00", null], parseExpiry("$y-05-01T12:30"), 'datetime-local');
    eq(["$y-05-01 23:59:59", null], parseExpiry("$y-05-01"), 'bare date = end of day');
    eq(["$y-05-01 09:30:00", null], parseExpiry("$y-05-01T12:30:00+03:00"), 'offset converted to UTC');
    eq(["$y-05-01 12:30:00", null], parseExpiry("$y-05-01T12:30:00Z"));
    eq([null, null], parseExpiry(''));
    eq([null, null], parseExpiry(null));
    foreach (["$y-02-31", "$y-05-01 12:61", 'tomorrow', '+1 day', '01.05.2030', "$y-05-01 25:00"] as $bad) {
        ok(parseExpiry($bad)[1] !== null, "rejects $bad");
    }
    ok(parseExpiry('2020-01-01 00:00')[1] !== null, 'rejects past dates');
});

test('expiry: parseMaxClicks', function () {
    app();
    eq([5, null], parseMaxClicks('5'));
    eq([7, null], parseMaxClicks(7));
    eq([null, null], parseMaxClicks(''));
    foreach (['0', '-1', '1.5', 'abc', '99999999999', 0] as $bad) ok(parseMaxClicks($bad)[1] !== null, 'rejects ' . var_export($bad, true));
});

test('expiry: click limit returns 410 after the last allowed click; bots never consume it', function () {
    $l = api('POST', '/api.php', ['url' => 'https://example.com/limited', 'max_clicks' => 2])->json();
    eq(2, $l['max_clicks']);
    $bot = new Client();
    $bot->headers = ['User-Agent: Googlebot/2.1'];
    eq(302, $bot->get('/' . $l['code'])->status, 'bot before limit');
    eq(302, (new Client())->get('/' . $l['code'])->status);
    eq(302, (new Client())->get('/' . $l['code'])->status);
    $gone = (new Client())->get('/' . $l['code']);
    eq(410, $gone->status);
    contains('click limit', $gone->body);
    eq(410, $bot->get('/' . $l['code'])->status, 'bot after limit');
    $info = api('GET', '/api.php?code=' . $l['code'])->json()['link'];
    eq(2, $info['clicks']);
    eq('limit', $info['state']);
});

test('expiry: click limit holds under concurrent requests', function () {
    $l = api('POST', '/api.php', ['url' => 'https://example.com/race', 'max_clicks' => 5])->json();
    $codes = parallelGet('/' . $l['code'], 20);
    eq(5, count(array_filter($codes, fn($c) => $c === 302)), 'redirects: ' . implode(',', $codes));
    eq(15, count(array_filter($codes, fn($c) => $c === 410)));
    app();
    eq(5, (int)db()->query('SELECT COUNT(*) FROM clicks WHERE link_id = ' . (int)$l['id'])->fetchColumn(), 'click log rows');
    eq(5, api('GET', '/api.php?code=' . $l['code'])->json()['link']['clicks']);
});

test('expiry: expired links return 410 with the date', function () {
    $l = api('POST', '/api.php', ['url' => 'https://example.com/soon', 'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600)])->json();
    eq(302, (new Client())->get('/' . $l['code'])->status);
    app();
    db()->prepare("UPDATE links SET expires_at = '2001-02-03 04:05:00' WHERE id = ?")->execute([$l['id']]);
    $r = (new Client())->get('/' . $l['code']);
    eq(410, $r->status);
    contains('2001-02-03 04:05', $r->body);
    eq('expired', api('GET', '/api.php?code=' . $l['code'])->json()['link']['state']);
    eq(1, api('GET', '/api.php?q=example.com/soon&state=expired')->json()['total'], 'API state filter');
});

test('expiry: API create/patch validation and clearing limits', function () {
    $y = (int)gmdate('Y') + 1;
    eq(422, api('POST', '/api.php', ['url' => 'https://example.com/x', 'expires_at' => 'soon'])->status);
    eq(422, api('POST', '/api.php', ['url' => 'https://example.com/x', 'max_clicks' => 0])->status);
    $l = api('POST', '/api.php', ['url' => 'https://example.com/patch-me', 'expires_at' => "$y-01-01T00:00:00+02:00", 'max_clicks' => 1])->json();
    eq(($y - 1) . '-12-31 22:00:00', $l['expires_at'], 'stored in UTC');
    eq(302, (new Client())->get('/' . $l['code'])->status);
    eq(410, (new Client())->get('/' . $l['code'])->status, 'limit of 1 reached');
    eq(422, api('PATCH', '/api.php?code=' . $l['code'], ['max_clicks' => -5])->status);
    eq(422, api('PATCH', '/api.php?code=' . $l['code'], ['nothing' => 1])->status);
    $p = api('PATCH', '/api.php?code=' . $l['code'], ['max_clicks' => null, 'expires_at' => '', 'title' => 'Unlimited now'])->json();
    eq(null, $p['link']['max_clicks']);
    eq(null, $p['link']['expires_at']);
    eq('Unlimited now', $p['link']['title']);
    eq('active', $p['link']['state']);
    eq(302, (new Client())->get('/' . $l['code'])->status, 'works again after clearing');
});

test('expiry: admin create form, list labels, filter and settings', function () {
    $y = (int)gmdate('Y') + 1;
    $c = (new Client())->login();
    $t = $c->get('/admin.php')->csrf();
    $c->post('/admin.php', ['csrf' => $t, 'action' => 'create', 'url' => 'https://example.com/admin-limits',
        'title' => 'Limited', 'expires_at' => gmdate('Y-m-d\TH:i', time() + 3 * 86400 + 600), 'max_clicks' => '10']);
    $list = $c->get('/admin.php?q=admin-limits');
    contains('3 d left', $list->body);
    contains('0 / 10 clicks', $list->body);

    $c->post('/admin.php', ['csrf' => $t, 'action' => 'create', 'url' => 'https://example.com/admin-bad', 'expires_at' => '2001-01-01T00:00']);
    contains('must be in the future', $c->get('/admin.php')->body, 'validation error shown');

    ok(preg_match('~admin\.php\?id=(\d+)" class="code"~', $list->body, $m) === 1);
    $id = $m[1];
    $stats = $c->get('/admin.php?id=' . $id);
    contains('name="max_clicks"', $stats->body);
    contains('value="10"', $stats->body);
    eq(302, $c->post('/admin.php', ['csrf' => $t, 'action' => 'update', 'id' => $id, 'back' => '?id=' . $id,
        'title' => 'Renamed', 'expires_at' => "$y-06-01T10:00", 'max_clicks' => ''])->status);
    $stats = $c->get('/admin.php?id=' . $id);
    contains('Saved.', $stats->body);
    contains('Renamed', $stats->body);
    contains('value="' . $y . '-06-01T10:00"', $stats->body);

    app();
    db()->prepare("UPDATE links SET expires_at = '2001-01-01 00:00:00' WHERE id = ?")->execute([$id]);
    $exp = $c->get('/admin.php?status=expired&q=admin-limits');
    contains('badge warn', $exp->body);
    contains('Renamed', $exp->body);
    notContains('Renamed', $c->get('/admin.php?status=active&q=admin-limits')->body);
});
