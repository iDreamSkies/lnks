<?php
/* Password-protected links. */

function protectedLink(string $url, string $password = 'open-sesame', array $extra = []): array {
    $r = api('POST', '/api.php', ['url' => $url, 'password' => $password] + $extra);
    eq(201, $r->status, $r->body);
    return $r->json();
}

test('password: visitors see a form, never the destination', function () {
    $l = protectedLink('https://example.com/secret-target');
    eq(true, $l['protected']);
    $r = (new Client())->get('/' . $l['code']);
    eq(200, $r->status);
    eq(null, $r->header('Location'));
    contains('type="password"', $r->body);
    notContains('secret-target', $r->body, 'destination not leaked');
    contains('no-store', (string)$r->header('Cache-Control'));
    // Browsers apply CSP form-action to the redirect after submit: the off-site destination must be allowed here…
    contains("form-action 'self' https: http:", (string)$r->header('Content-Security-Policy'));
    // …but nowhere else
    contains("form-action 'self';", (string)(new Client())->get('/')->header('Content-Security-Policy'));
    eq(200, (new Client())->request('HEAD', '/' . $l['code'])->status, 'HEAD shows the form too');
    eq(0, api('GET', '/api.php?code=' . $l['code'])->json()['link']['clicks'], 'viewing the form is not a click');
});

test('password: wrong password is rejected, the right one redirects and counts one click', function () {
    $l = protectedLink('https://example.com/unlock-me');
    $c = new Client();
    $bad = $c->post('/' . $l['code'], ['password' => 'nope']);
    eq(200, $bad->status);
    contains('Wrong password', $bad->body);
    notContains('unlock-me', $bad->body);
    $ok = $c->post('/' . $l['code'], ['password' => 'open-sesame']);
    eq(303, $ok->status);
    eq('https://example.com/unlock-me', $ok->header('Location'));
    eq(1, api('GET', '/api.php?code=' . $l['code'])->json()['link']['clicks']);
});

test('password: 5 failures per IP lock the link for that IP (429), even for the right password', function () {
    $l = protectedLink('https://example.com/brute');
    $other = protectedLink('https://example.com/brute-other');
    $c = new Client();
    for ($i = 0; $i < 5; $i++) eq(200, $c->post('/' . $l['code'], ['password' => 'guess' . $i])->status);
    $r = $c->post('/' . $l['code'], ['password' => 'open-sesame']);
    eq(429, $r->status);
    contains('Too many attempts', $r->body);
    eq(303, $c->post('/' . $other['code'], ['password' => 'open-sesame'])->status, 'other links are not affected');
});

test('password: expired or used-up links answer 410 before asking for a password', function () {
    $l = protectedLink('https://example.com/pw-limit', 'open-sesame', ['max_clicks' => 1]);
    eq(303, (new Client())->post('/' . $l['code'], ['password' => 'open-sesame'])->status);
    eq(410, (new Client())->get('/' . $l['code'])->status);
    eq(410, (new Client())->post('/' . $l['code'], ['password' => 'open-sesame'])->status);
});

test('password: unprotected links still refuse POST; validation and API never expose the hash', function () {
    $plain = api('POST', '/api.php', ['url' => 'https://example.com/plain-post'])->json();
    eq(405, (new Client())->post('/' . $plain['code'], ['password' => 'x'])->status);
    eq(422, api('POST', '/api.php', ['url' => 'https://example.com/short-pw', 'password' => 'abc'])->status);

    $l = protectedLink('https://example.com/api-pw');
    $info = api('GET', '/api.php?code=' . $l['code']);
    notContains('password_hash', $info->body);
    notContains('$2y$', $info->body);
    eq(false, api('PATCH', '/api.php?code=' . $l['code'], ['password' => null])->json()['link']['protected'], 'removed');
    eq(302, (new Client())->get('/' . $l['code'])->status, 'now public');
    eq(true, api('PATCH', '/api.php?code=' . $l['code'], ['password' => 'new-pass'])->json()['link']['protected']);
    eq(303, (new Client())->post('/' . $l['code'], ['password' => 'new-pass'])->status);

    $c = (new Client())->login();
    $csv = $c->get('/admin.php?export=csv')->body;
    notContains('$2y$', $csv, 'hashes are not exported');
});

test('password: admin sets, keeps and removes a password', function () {
    $c = (new Client())->login();
    $t = $c->get('/admin.php')->csrf();
    $c->post('/admin.php', ['csrf' => $t, 'action' => 'create', 'url' => 'https://example.com/admin-pw', 'title' => 'Admin PW', 'password' => 'from-admin']);
    $list = $c->get('/admin.php?q=Admin+PW');
    contains('badge lock', $list->body);
    ok(preg_match('~admin\.php\?id=(\d+)" class="code">([^<]+)<~', $list->body, $m) === 1);
    [$id, $code] = [$m[1], $m[2]];
    eq(303, (new Client())->post('/' . $code, ['password' => 'from-admin'])->status);

    // Saving settings with an empty password field keeps the password
    $c->post('/admin.php', ['csrf' => $t, 'action' => 'update', 'id' => $id, 'title' => 'Admin PW 2', 'expires_at' => '', 'max_clicks' => '', 'password' => '']);
    eq(true, api('GET', '/api.php?code=' . $code)->json()['link']['protected']);
    contains('name="password_remove"', $c->get('/admin.php?id=' . $id)->body);

    $c->post('/admin.php', ['csrf' => $t, 'action' => 'update', 'id' => $id, 'title' => 'Admin PW 2', 'expires_at' => '', 'max_clicks' => '', 'password' => '', 'password_remove' => '1']);
    eq(false, api('GET', '/api.php?code=' . $code)->json()['link']['protected']);
    eq(302, (new Client())->get('/' . $code)->status);
});
