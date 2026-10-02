<?php
/* CSV import / export, including the YOURLS format. */

function loadCsv(): void {
    app();
    require_once instance()->dir . '/csv.php';
}

test('csv: parser handles BOM, semicolons (Excel) and a header-less list of URLs', function () {
    loadCsv();
    $p = parseCsv("\xEF\xBB\xBFurl;title\r\nhttps://a.example/1;Один\r\n\r\nhttps://a.example/2;\"Два; три\"\r\n");
    eq(2, count($p['rows']));
    eq('Один', $p['rows'][0]['title']);
    eq('Два; три', $p['rows'][1]['title']);
    eq([], $p['errors']);

    $u = parseCsv("https://b.example/1\nhttps://b.example/2\nnot a url\n");
    eq('urls', $u['format']);
    eq(2, count($u['rows']));
    eq(1, count($u['errors']));
    eq(3, $u['errors'][0]['line']);
});

test('csv: phpMyAdmin NULL cells are treated as empty', function () {
    loadCsv();
    $p = parseCsv("keyword;url;title;timestamp;ip;clicks\nnul1;https://n.example/1;NULL;2014-01-01 00:00:00;NULL;4\n");
    eq([], $p['errors']);
    eq(null, $p['rows'][0]['title']);
    eq(null, $p['rows'][0]['ip']);
    eq(4, $p['rows'][0]['clicks']);
    eq('yourls', $p['format']);
});

test('csv: missing url column, empty file and bad encoding are reported', function () {
    loadCsv();
    ok(parseCsv("name,title\nx,y\n")['errors'][0]['error'] !== '', 'no url header');
    eq(0, count(parseCsv("code,url\n")['rows']));
    ok(count(parseCsv('')['errors']) === 1, 'empty');
    ok(count(parseCsv("url\n\xff\xfe")['errors']) === 1, 'not UTF-8');
});

test('csv: codes — YOURLS-style keywords allowed, reserved words rejected', function () {
    app();
    foreach (['1', 'a', 'my-link', 'x_y', 'Ab3', str_repeat('z', 32)] as $ok) ok(isValidCode($ok), "accepts $ok");
    foreach (['admin', 'API', 'public', 'storage', 'license', '-x', '_x', 'a b', 'a.b', str_repeat('z', 33), ''] as $bad) ok(!isValidCode($bad), "rejects $bad");
});

test('csv: export neutralises spreadsheet formulas and survives a round trip', function () {
    loadCsv();
    $r = createLink(db(), 'https://example.com/formula', null, ['title' => '=HYPERLINK("http://evil")']);
    $fh = fopen('php://memory', 'w+');
    exportCsv(db(), $fh);
    rewind($fh);
    $csv = stream_get_contents($fh);
    eq("\xEF\xBB\xBF", substr($csv, 0, 3), 'BOM for Excel');
    contains('code,url,title,clicks,status,created_at,last_click_at,expires_at,max_clicks', $csv);
    contains('"\'=HYPERLINK(""http://evil"")"', $csv, 'formula prefixed with an apostrophe');
    $p = parseCsv($csv);
    $mine = array_values(array_filter($p['rows'], fn($x) => $x['code'] === $r['code']));
    eq('=HYPERLINK("http://evil")', $mine[0]['title'], 'title restored on import');
    $plan = planImport(db(), $p['rows']);
    eq(0, count($plan['new']), 'nothing new when re-importing an export');
    eq(count($p['rows']), count($plan['duplicates']));
});

test('csv: admin import of a YOURLS export keeps keywords, clicks and dates', function () {
    $c = (new Client())->login();
    $page = $c->get('/admin.php?view=io');
    contains('enctype="multipart/form-data"', $page->body);
    $csv = (string)file_get_contents(__DIR__ . '/fixtures/yourls-export.csv');

    $pre = $c->upload('/admin.php', ['csrf' => $page->csrf(), 'action' => 'import_preview'], 'file', 'yourls.csv', $csv);
    eq(200, $pre->status);
    contains('Rows: 9 · new: 5 · duplicates: 1 · errors: 3', $pre->body);
    contains('YOURLS format detected', $pre->body);
    contains('Invalid code', $pre->body);
    contains('repeated in the file', $pre->body);
    ok(preg_match('~name="import_id" value="([a-f0-9]{24})"~', $pre->body, $m) === 1, 'confirm form');

    $run = $c->post('/admin.php', ['csrf' => $page->csrf(), 'action' => 'import_run', 'import_id' => $m[1]]);
    eq(302, $run->status);
    contains('Imported 5 links, skipped 1 duplicates.', $c->get('/admin.php')->body);

    $r = (new Client())->get('/1');
    eq(302, $r->status, 'one-char YOURLS keyword redirects');
    eq('https://example.com/first', $r->header('Location'));
    eq('https://example.com/dash', (new Client())->get('/my-link')->header('Location'));
    eq('https://example.com/under', (new Client())->get('/x_y')->header('Location'));
    eq(404, (new Client())->get('/admin')->status, 'reserved word is not a short code');
    eq(404, api('GET', '/api.php?code=admin')->status, 'reserved word was not imported');
    eq(404, api('GET', '/api.php?code=nourl')->status, 'row with a bad URL was not imported');

    $one = api('GET', '/api.php?code=1')->json()['link'];
    eq(16, $one['clicks'], '15 imported clicks + 1 visit');
    eq('First, with comma', $one['title']);
    eq('2014-01-01 10:00:00', $one['created_at']);
    eq('Quote "inside"', api('GET', '/api.php?code=my-link')->json()['link']['title']);
    eq('Line one Line two', api('GET', '/api.php?code=multi')->json()['link']['title']);

    // The confirmation cannot be replayed, and importing the same file again adds nothing
    eq(302, $c->post('/admin.php', ['csrf' => $page->csrf(), 'action' => 'import_run', 'import_id' => $m[1]])->status);
    contains('preview has expired', $c->get('/admin.php?view=io')->body);
    $again = $c->upload('/admin.php', ['csrf' => $page->csrf(), 'action' => 'import_preview'], 'file', 'yourls.csv', $csv);
    contains('new: 0 · duplicates: 6', $again->body);
    contains('Nothing to import.', $again->body);
});

test('csv: export is admin-only and downloads a CSV file', function () {
    $anon = (new Client())->get('/admin.php?export=csv');
    notContains('code,url,title', $anon->body);
    $r = (new Client())->login()->get('/admin.php?export=csv');
    eq(200, $r->status);
    contains('text/csv', (string)$r->header('Content-Type'));
    contains('attachment; filename="lnks-', (string)$r->header('Content-Disposition'));
    contains('code,url,title,clicks', $r->body);
});

test('csv: API dry run, import and export', function () {
    $csv = "code,url,title,max_clicks,expires_at,status\napi-one,https://example.com/api-one,From API,3,,active\n"
        . ",https://example.com/api-gen,Generated code,,2001-01-01,disabled\nbad!,https://example.com/x,,,,\n";
    $h = ['Authorization: Bearer ' . TEST_API_TOKEN, 'Content-Type: text/csv'];
    $dry = (new Client())->request('POST', '/api.php?import=csv&dry_run=1', $csv, $h)->json();
    eq(true, $dry['dry_run']);
    eq(2, $dry['would_import']);
    eq(1, count($dry['errors']));
    eq(404, api('GET', '/api.php?code=api-one')->status, 'dry run wrote nothing');

    $res = (new Client())->request('POST', '/api.php?import=csv', $csv, $h)->json();
    eq(2, $res['imported']);
    eq(4, $res['errors'][0]['line']);
    $one = api('GET', '/api.php?code=api-one')->json()['link'];
    eq(3, $one['max_clicks']);
    $gen = api('GET', '/api.php?q=api-gen')->json()['items'][0];
    eq('disabled', $gen['state']);
    eq('2001-01-01 23:59:59', $gen['expires_at'], 'past expiry allowed on import');

    $up = (new Client())->upload('/api.php?import=csv&dry_run=1', [], 'file', 'x.csv', "url\nhttps://example.com/multipart\n", ['Authorization: Bearer ' . TEST_API_TOKEN]);
    eq(1, $up->json()['would_import'], 'multipart upload');

    $exp = (new Client())->request('GET', '/api.php?export=csv', null, ['Authorization: Bearer ' . TEST_API_TOKEN]);
    contains('api-one,https://example.com/api-one,From API', $exp->body);
    eq(401, (new Client())->get('/api.php?export=csv')->status);
});
