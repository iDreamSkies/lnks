<?php
/* Bulk actions on several links at once. */

function bulkLinks(string $prefix, int $n): array {
    $out = [];
    for ($i = 1; $i <= $n; $i++) $out[] = api('POST', '/api.php', ['url' => "https://example.com/$prefix-$i", 'title' => "$prefix $i"])->json();
    return $out;
}

test('bulk: admin disables, tags, untags and deletes selected links only', function () {
    [$a, $b, $c] = bulkLinks('bulk-admin', 3);
    $cl = (new Client())->login();
    $page = $cl->get('/admin.php?q=bulk-admin');
    contains('id="bulk"', $page->body);
    contains('name="ids[]" value="' . $a['id'] . '" form="bulk"', $page->body, 'row checkboxes belong to the bulk form');
    $t = $page->csrf();
    $post = fn(array $f) => $cl->request('POST', '/admin.php', http_build_query(['csrf' => $t, 'action' => 'bulk', 'back' => '?q=bulk-admin'] + $f), ['Content-Type: application/x-www-form-urlencoded']);

    eq(302, $post(['op' => 'disable', 'ids' => [$a['id'], $b['id']]])->status);
    contains('Updated links: 2.', $cl->get('/admin.php?q=bulk-admin')->body);
    eq('disabled', api('GET', '/api.php?code=' . $a['code'])->json()['link']['state']);
    eq('active', api('GET', '/api.php?code=' . $c['code'])->json()['link']['state'], 'unselected link untouched');

    $post(['op' => 'enable', 'ids' => [$a['id']]]);
    eq('active', api('GET', '/api.php?code=' . $a['code'])->json()['link']['state']);

    $post(['op' => 'tag', 'tags' => 'campaign, q4', 'ids' => [$a['id'], $c['id']]]);
    eq(['campaign', 'q4'], api('GET', '/api.php?code=' . $c['code'])->json()['link']['tags']);
    eq([], api('GET', '/api.php?code=' . $b['code'])->json()['link']['tags']);
    $post(['op' => 'untag', 'tags' => 'q4', 'ids' => [$a['id'], $c['id']]]);
    eq(['campaign'], api('GET', '/api.php?code=' . $a['code'])->json()['link']['tags']);

    $post(['op' => 'tag', 'tags' => '', 'ids' => [$a['id']]]);
    contains('Enter at least one tag.', $cl->get('/admin.php')->body);
    $post(['op' => 'explode', 'ids' => [$a['id']]]);
    contains('Unknown bulk action.', $cl->get('/admin.php')->body);
    $post(['op' => 'disable']);
    contains('No links selected.', $cl->get('/admin.php')->body);
    $post(['op' => 'disable', 'ids' => [999999]]);
    contains('No links selected.', $cl->get('/admin.php')->body, 'unknown ids are ignored');

    $post(['op' => 'delete', 'ids' => [$a['id'], $c['id']]]);
    eq(404, api('GET', '/api.php?code=' . $a['code'])->status);
    eq(404, api('GET', '/api.php?code=' . $c['code'])->status);
    eq(200, api('GET', '/api.php?code=' . $b['code'])->status);
    $tags = array_column(api('GET', '/api.php?tags=1')->json()['items'], 'name');
    ok(!in_array('campaign', $tags, true), 'tags of deleted links pruned');

    eq(400, $cl->post('/admin.php', ['csrf' => 'bad', 'action' => 'bulk', 'op' => 'delete', 'ids' => [$b['id']]])->status, 'CSRF');
    eq(200, api('GET', '/api.php?code=' . $b['code'])->status);
});

test('bulk: API by codes', function () {
    [$a, $b] = bulkLinks('bulk-api', 2);
    $r = api('POST', '/api.php?bulk=1', ['codes' => [$a['code'], $b['code'], 'no-such-code'], 'op' => 'tag', 'tags' => ['api-bulk']]);
    eq(200, $r->status, $r->body);
    eq(2, $r->json()['affected'], 'unknown codes ignored');
    eq(2, api('GET', '/api.php?tag=api-bulk')->json()['total']);
    eq(2, api('POST', '/api.php?bulk=1', ['codes' => [$a['code'], $b['code']], 'op' => 'disable'])->json()['affected']);
    eq(1, api('GET', '/api.php?tag=api-bulk&state=disabled&q=bulk-api-1')->json()['total']);
    eq(422, api('POST', '/api.php?bulk=1', ['codes' => [], 'op' => 'disable'])->status);
    eq(422, api('POST', '/api.php?bulk=1', ['codes' => [$a['code']], 'op' => 'nope'])->status);
    eq(401, api('POST', '/api.php?bulk=1', ['codes' => [$a['code']], 'op' => 'delete'], 'bad-token')->status);
    eq(2, api('POST', '/api.php?bulk=1', ['codes' => [$a['code'], $b['code']], 'op' => 'delete'])->json()['affected']);
    eq(404, api('GET', '/api.php?code=' . $a['code'])->status);
});
