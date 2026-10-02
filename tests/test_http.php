<?php
/* End-to-end checks against the built-in server. */

function newLinkViaApi(string $url = 'https://example.com/target', array $extra = []): array {
    $r = api('POST', '/api.php', ['url' => $url] + $extra);
    eq(201, $r->status, 'API create: ' . $r->body);
    return $r->json();
}

test('http: homepage renders with security headers', function () {
    $c = new Client();
    $r = $c->get('/');
    eq(200, $r->status);
    contains('<form method="post" class="shorten"', $r->body);
    contains("default-src 'self'", (string)$r->header('Content-Security-Policy'));
    eq('DENY', $r->header('X-Frame-Options'));
});

test('http: public form creates a link (CSRF required)', function () {
    $c = new Client();
    $t = $c->get('/')->csrf();
    eq(400, $c->post('/', ['csrf' => 'bad', 'url' => 'https://example.com/x'])->status, 'bad csrf');
    $r = $c->post('/', ['csrf' => $t, 'url' => 'https://example.com/form']);
    eq(200, $r->status);
    ok(preg_match('~data-copy="(http[^"]+/([A-Za-z0-9]{6}))"~', $r->body, $m) === 1, 'short url shown');
});

test('http: redirect counts humans, ignores bots and HEAD', function () {
    $l = newLinkViaApi('https://example.com/redirect');
    $c = new Client();
    $r = $c->get('/' . $l['code']);
    eq(302, $r->status);
    eq('https://example.com/redirect', $r->header('Location'));
    ok(!$c->hasCookie('lnks_sess'), 'no session cookie for redirects');

    $bot = new Client();
    $bot->headers = ['User-Agent: Googlebot/2.1'];
    eq(302, $bot->get('/' . $l['code'])->status);
    eq(302, (new Client())->request('HEAD', '/' . $l['code'])->status);
    eq(405, (new Client())->post('/' . $l['code'], [])->status);

    eq(1, api('GET', '/api.php?code=' . $l['code'])->json()['link']['clicks'], 'only the human click counted');
});

test('http: unknown and disabled codes return 404', function () {
    eq(404, (new Client())->get('/Zz9Zz9Zz')->status);
    $l = newLinkViaApi();
    eq(200, api('PATCH', '/api.php?code=' . $l['code'], ['status' => 0])->status);
    eq(404, (new Client())->get('/' . $l['code'])->status);
});

test('http: internal files are not served', function () {
    $c = new Client();
    foreach (['/config.php', '/storage/lnks.sqlite', '/bootstrap.php', '/layout.php', '/i18n.php', '/lang/en.php', '/schema.sql', '/README.md', '/tests/run.php'] as $p) {
        eq(403, $c->get($p)->status, $p);
    }
    eq(200, $c->get('/index.php')->status, '/index.php is the homepage');
});

test('http: admin login requires username and password, logout works', function () {
    $c = new Client();
    $t = $c->get('/admin.php')->csrf();
    $r = $c->post('/admin.php', ['csrf' => $t, 'username' => 'admin', 'password' => TEST_ADMIN_PASS]);
    contains('Wrong username or password', $r->body);
    $c->login();
    $page = $c->get('/admin.php');
    contains('Links', $page->body);
    eq(302, $c->post('/admin.php', ['csrf' => $page->csrf(), 'action' => 'logout'])->status);
    contains('Admin login', $c->get('/admin.php')->body);
});

test('http: admin can create, disable and delete links', function () {
    $c = (new Client())->login();
    $t = $c->get('/admin.php')->csrf();
    eq(302, $c->post('/admin.php', ['csrf' => $t, 'action' => 'create', 'url' => 'https://example.com/admin-made', 'title' => 'Made in admin'])->status);
    $page = $c->get('/admin.php?q=admin-made');
    contains('Made in admin', $page->body);
    ok(preg_match('~name="id" value="(\d+)"~', $page->body, $m) === 1);
    $id = $m[1];
    $c->post('/admin.php', ['csrf' => $t, 'action' => 'toggle', 'id' => $id]);
    contains('badge off', $c->get('/admin.php?q=admin-made')->body);
    $c->post('/admin.php', ['csrf' => $t, 'action' => 'delete', 'id' => $id]);
    contains('Nothing matches', $c->get('/admin.php?q=admin-made')->body);
});

test('http: API auth, validation and listing', function () {
    eq(401, api('GET', '/api.php', null, 'wrong')->status);
    eq(422, api('POST', '/api.php', ['url' => 'ftp://nope'])->status);
    eq(400, (new Client())->request('POST', '/api.php', '{bad', ['Authorization: Bearer ' . TEST_API_TOKEN, 'Content-Type: application/json'])->status);
    newLinkViaApi('https://example.com/listing-check', ['title' => 'Listing check']);
    $list = api('GET', '/api.php?q=listing-check')->json();
    eq(1, $list['total']);
    eq('Listing check', $list['items'][0]['title']);
    $code = $list['items'][0]['code'];
    eq(200, api('DELETE', '/api.php?code=' . $code)->status);
    eq(404, api('GET', '/api.php?code=' . $code)->status);
});

test('http: language switch (EN/RU) is remembered, API stays English', function () {
    $c = new Client();
    contains('<html lang="en"', $c->get('/')->body);
    contains('<html lang="ru"', $c->get('/?lang=ru')->body);
    contains('Сократить', $c->get('/')->body, 'cookie remembered');
    $c->headers[] = 'Authorization: Bearer ' . TEST_API_TOKEN;
    contains('Invalid URL', $c->post('/api.php', ['url' => 'nope'])->body);
});
