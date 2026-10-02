<?php
/* Custom short names (aliases) and per-domain links. */

test('aliases: custom short name via API, uniqueness and validation', function () {
    $r = api('POST', '/api.php', ['url' => 'https://example.com/spring', 'code' => 'spring-sale']);
    eq(201, $r->status, $r->body);
    $l = $r->json();
    eq('spring-sale', $l['code']);
    ok(substr($l['short_url'], -12) === '/spring-sale', 'short url uses the alias');
    eq('https://example.com/spring', (new Client())->get('/spring-sale')->header('Location'));

    $dup = api('POST', '/api.php', ['url' => 'https://example.com/other', 'code' => 'spring-sale']);
    eq(422, $dup->status);
    contains('already taken', $dup->body);
    foreach (['bad code', 'admin', 'Login', '-x', str_repeat('a', 33)] as $bad) {
        eq(422, api('POST', '/api.php', ['url' => 'https://example.com/x', 'alias' => $bad])->status, "rejects $bad");
    }
    eq(201, api('POST', '/api.php', ['url' => 'https://example.com/one', 'alias' => 'Z'])->status, 'one-char alias');
});

test('aliases: a link bound to a domain answers only on that host', function () {
    $r = api('POST', '/api.php', ['url' => 'https://example.com/brand', 'code' => 'brand', 'domain' => 'GO.test']);
    eq(201, $r->status, $r->body);
    $l = $r->json();
    eq('go.test', $l['domain']);
    eq('http://go.test/brand', $l['short_url']);

    eq(302, (new Client())->get('/brand', ['Host: go.test'])->status, 'right host');
    eq(404, (new Client())->get('/brand', ['Host: alt.test'])->status, 'other configured host');
    eq(404, (new Client())->get('/brand')->status, 'primary host');

    $any = api('POST', '/api.php', ['url' => 'https://example.com/anywhere'])->json();
    eq(null, $any['domain']);
    eq(302, (new Client())->get('/' . $any['code'], ['Host: alt.test'])->status, 'unbound links work on every host');

    eq(422, api('POST', '/api.php', ['url' => 'https://example.com/x', 'domain' => 'evil.test'])->status, 'unknown domain');
    eq(422, api('POST', '/api.php', ['url' => 'https://go.test/loop'])->status, 'no links to our own domains');
    eq('go.test', api('GET', '/api.php?code=brand')->json()['link']['domain']);
});

test('aliases: admin form sets short name and domain', function () {
    $c = (new Client())->login();
    $page = $c->get('/admin.php');
    contains('name="code"', $page->body);
    contains('<option value="go.test">go.test</option>', $page->body, 'domain picker when domains are configured');
    $c->post('/admin.php', ['csrf' => $page->csrf(), 'action' => 'create', 'url' => 'https://example.com/admin-alias',
        'title' => 'Admin alias', 'code' => 'team', 'domain' => 'alt.test']);
    $list = $c->get('/admin.php?q=Admin+alias');
    contains('>team<', $list->body);
    contains('alt.test/team', $list->body);
    contains('data-copy="http://alt.test/team"', $list->body, 'copy/QR use the bound domain');

    $c->post('/admin.php', ['csrf' => $page->csrf(), 'action' => 'create', 'url' => 'https://example.com/x', 'code' => 'team']);
    contains('already taken', $c->get('/admin.php')->body);
});

test('aliases: CSV carries the domain column both ways', function () {
    $h = ['Authorization: Bearer ' . TEST_API_TOKEN, 'Content-Type: text/csv'];
    $res = (new Client())->request('POST', '/api.php?import=csv',
        "code,url,domain\ncsv-dom,https://example.com/csv-dom,go.test\ncsv-bad,https://example.com/csv-bad,nope.test\n", $h)->json();
    eq(1, $res['imported']);
    eq(3, $res['errors'][0]['line']);
    eq('go.test', api('GET', '/api.php?code=csv-dom')->json()['link']['domain']);
    $exp = (new Client())->request('GET', '/api.php?export=csv', null, ['Authorization: Bearer ' . TEST_API_TOKEN])->body;
    contains(',max_clicks,domain', $exp);
    contains('csv-dom,https://example.com/csv-dom,', $exp);
    ok(preg_match('~csv-dom,[^\r\n]*,go\.test\r\n~', $exp) === 1, 'domain exported');
});
