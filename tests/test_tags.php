<?php
/* Tags: parsing, API, admin, CSV, cleanup. */

test('tags: parsing, de-duplication and limits', function () {
    app();
    eq([['promo', 'Autumn 2026', 'b-2_x'], null], parseTags(' promo , Autumn  2026,, PROMO, b-2_x '));
    eq([['Промо', 'осень'], null], parseTags(['Промо', 'осень']));
    eq([[], null], parseTags(''));
    ok(parseTags('bad!tag')[1] !== null, 'invalid characters');
    ok(parseTags(str_repeat('x', 33))[1] !== null, 'too long');
    ok(parseTags(implode(',', range(1, 11)))[1] !== null, 'more than 10');
});

test('tags: API create, filter, list with counts, replace and clear', function () {
    $a = api('POST', '/api.php', ['url' => 'https://example.com/t1', 'tags' => ['news', 'Autumn']])->json();
    $b = api('POST', '/api.php', ['url' => 'https://example.com/t2', 'tags' => 'news'])->json();
    eq(['news', 'Autumn'], $a['tags']);
    eq(422, api('POST', '/api.php', ['url' => 'https://example.com/t3', 'tags' => 'no!'])->status);

    $list = api('GET', '/api.php?tag=NEWS')->json();   // case-insensitive
    eq(2, $list['total']);
    eq(['Autumn', 'news'], api('GET', '/api.php?code=' . $a['code'])->json()['link']['tags'], 'sorted');

    $counts = array_column(api('GET', '/api.php?tags=1')->json()['items'], 'links', 'name');
    eq(2, $counts['news']);
    eq(1, $counts['Autumn']);

    eq(['sale'], api('PATCH', '/api.php?code=' . $a['code'], ['tags' => ['sale']])->json()['link']['tags']);
    $counts = array_column(api('GET', '/api.php?tags=1')->json()['items'], 'links', 'name');
    ok(!isset($counts['Autumn']), 'unused tag removed');
    eq([], api('PATCH', '/api.php?code=' . $a['code'], ['tags' => []])->json()['link']['tags']);
    api('DELETE', '/api.php?code=' . $b['code']);
    $counts = array_column(api('GET', '/api.php?tags=1')->json()['items'], 'links', 'name');
    ok(!isset($counts['sale']) && !isset($counts['news']), 'deleting links prunes their tags');
});

test('tags: admin sets tags, shows chips and filters by tag', function () {
    $c = (new Client())->login();
    $t = $c->get('/admin.php')->csrf();
    $c->post('/admin.php', ['csrf' => $t, 'action' => 'create', 'url' => 'https://example.com/admin-tags', 'title' => 'Tagged one', 'tags' => 'vk, Spring']);
    $c->post('/admin.php', ['csrf' => $t, 'action' => 'create', 'url' => 'https://example.com/admin-untagged', 'title' => 'Untagged one']);
    $page = $c->get('/admin.php');
    contains('>#Spring</a>', $page->body);
    contains('<datalist id="tag-list">', $page->body);
    contains('<option value="vk">#vk (1)</option>', $page->body, 'tag filter');

    $f = $c->get('/admin.php?tag=vk');
    contains('Tagged one', $f->body);
    notContains('Untagged one', $f->body);

    ok(preg_match('~admin\.php\?id=(\d+)" class="code">[^<]+</a>\s*(?:<span[^>]*>.*?</span>)?\s*<div class="muted small">Tagged one~s', $page->body, $m) === 1);
    $c->post('/admin.php', ['csrf' => $t, 'action' => 'update', 'id' => $m[1], 'title' => 'Tagged one', 'tags' => 'summer',
        'expires_at' => '', 'max_clicks' => '', 'password' => '']);
    $stats = $c->get('/admin.php?id=' . $m[1]);
    contains('value="summer"', $stats->body);
    contains('>#summer</a>', $stats->body);
    notContains('>#vk (', $c->get('/admin.php')->body, 'old tag pruned from the filter');
});

test('tags: CSV export and import', function () {
    $h = ['Authorization: Bearer ' . TEST_API_TOKEN, 'Content-Type: text/csv'];
    $res = (new Client())->request('POST', '/api.php?import=csv', "code,url,tags\ncsv-tags,https://example.com/csv-tags,\"alpha, Beta\"\n", $h)->json();
    eq(1, $res['imported']);
    eq(['alpha', 'Beta'], api('GET', '/api.php?code=csv-tags')->json()['link']['tags']);
    $exp = (new Client())->request('GET', '/api.php?export=csv', null, ['Authorization: Bearer ' . TEST_API_TOKEN])->body;
    contains(',domain,tags', $exp);
    contains(',"alpha, Beta"', $exp);
});
