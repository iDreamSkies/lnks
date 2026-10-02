<?php
/* UTM tags and saved templates. */

test('utm: applyUtm keeps other parameters verbatim, replaces existing tags, keeps the fragment', function () {
    app();
    $u = ['utm_source' => 'telegram', 'utm_medium' => 'social', 'utm_campaign' => 'Осень 2026'];
    eq('https://e.com/p?utm_source=telegram&utm_medium=social&utm_campaign=%D0%9E%D1%81%D0%B5%D0%BD%D1%8C%202026',
        applyUtm('https://e.com/p', $u));
    eq('https://e.com/p?a.b=1&x[]=2&x[]=3&ref=old&utm_source=telegram#section',
        applyUtm('https://e.com/p?a.b=1&utm_source=google&x[]=2&x[]=3&ref=old#section', ['utm_source' => 'telegram']));
    eq('https://e.com/?q=a%20b&utm_campaign=x', applyUtm('https://e.com/?q=a%20b&utm_campaign=old', ['utm_campaign' => 'x']));
    eq('https://e.com/p?k=v', applyUtm('https://e.com/p?k=v', []), 'no tags → unchanged');
});

test('utm: parsing, templates and validation', function () {
    app();
    eq([['utm_source' => 'a', 'utm_campaign' => 'c'], null], parseUtm(['source' => ' a ', 'medium' => '', 'utm_campaign' => 'c']));
    ok(parseUtm(['source' => str_repeat('x', 101)])[1] !== null, 'too long');
    $pdo = db();
    eq(true, saveUtmTemplate($pdo, 'Newsletter', ['source' => 'newsletter', 'medium' => 'email'])['ok']);
    ok(saveUtmTemplate($pdo, 'Newsletter', ['source' => 'x'])['error'] !== null, 'duplicate name');
    ok(saveUtmTemplate($pdo, '', ['source' => 'x'])['error'] !== null, 'name required');
    ok(saveUtmTemplate($pdo, 'Empty', [])['error'] !== null, 'at least one tag');
    [$utm] = resolveUtm($pdo, 'Newsletter', ['utm_medium' => 'push']);
    eq(['utm_source' => 'newsletter', 'utm_medium' => 'push'], $utm, 'explicit fields override the template');
    ok(resolveUtm($pdo, 'Nope', [])[1] !== null, 'unknown template');
});

test('utm: admin manages templates and a selected template is applied without JavaScript', function () {
    $c = (new Client())->login();
    $page = $c->get('/admin.php?view=utm');
    contains('UTM templates', $page->body);
    $t = $page->csrf();
    $c->post('/admin.php', ['csrf' => $t, 'action' => 'utm_add', 'back' => '?view=utm', 'name' => 'VK spring',
        'utm_source' => 'vk', 'utm_medium' => 'social', 'utm_campaign' => 'spring']);
    $page = $c->get('/admin.php?view=utm');
    contains('Template saved.', $page->body);
    contains('VK spring', $page->body);

    $list = $c->get('/admin.php');
    ok(preg_match('~<option value="(\d+)" data-utm="[^"]*">VK spring</option>~', $list->body, $m) === 1, 'template in picker');
    contains('list="utm-source-list"', $list->body, 'suggestions');
    // Browser without JS: only the template is chosen, the inputs stay empty
    $c->post('/admin.php', ['csrf' => $t, 'action' => 'create', 'url' => 'https://example.com/landing?ref=1', 'title' => 'UTM link',
        'utm_template' => $m[1], 'utm_source' => '', 'utm_medium' => '', 'utm_campaign' => '']);
    $l = api('GET', '/api.php?q=UTM+link')->json()['items'][0];
    eq('https://example.com/landing?ref=1&utm_source=vk&utm_medium=social&utm_campaign=spring', $l['url']);

    ok(preg_match('~<strong>VK spring</strong>.*?name="id" value="(\d+)"~s', $page->body, $d) === 1);
    $c->post('/admin.php', ['csrf' => $t, 'action' => 'utm_delete', 'id' => $d[1], 'back' => '?view=utm']);
    notContains('VK spring', $c->get('/admin.php?view=utm')->body);
    eq('https://example.com/landing?ref=1&utm_source=vk&utm_medium=social&utm_campaign=spring',
        api('GET', '/api.php?code=' . $l['code'])->json()['link']['url'], 'existing links keep their tags');
});

test('utm: API accepts tags and templates and lists templates', function () {
    app();
    saveUtmTemplate(db(), 'API tpl', ['source' => 'api', 'campaign' => 'launch']);
    $r = api('POST', '/api.php', ['url' => 'https://example.com/a#top', 'utm' => ['source' => 'yandex', 'medium' => 'cpc']])->json();
    eq('https://example.com/a?utm_source=yandex&utm_medium=cpc#top', $r['url']);
    $r = api('POST', '/api.php', ['url' => 'https://example.com/b', 'utm_template' => 'API tpl', 'utm' => ['medium' => 'qr']])->json();
    eq('https://example.com/b?utm_source=api&utm_medium=qr&utm_campaign=launch', $r['url']);
    eq(422, api('POST', '/api.php', ['url' => 'https://example.com/c', 'utm_template' => 'missing'])->status);
    $names = array_column(api('GET', '/api.php?utm_templates=1')->json()['items'], 'name');
    ok(in_array('API tpl', $names, true));
});
