<?php
/* Core helpers, link creation and validation (in-process). */

test('core: cut() truncates UTF-8 without mbstring', function () {
    app();
    eq('Приве…', cut('Привет мир', 6, '…'));
    eq('abc', cut('abc', 5, '…'));
});

test('core: createLink validates URLs', function () {
    app();
    $pdo = db();
    foreach (['javascript:alert(1)', 'ftp://example.com', 'not a url', "http://exa mple.com", ''] as $bad) {
        eq(false, createLink($pdo, $bad)['ok'], "rejects $bad");
    }
    eq(false, createLink($pdo, 'https://example.com/' . str_repeat('a', 2100))['ok'], 'rejects too long');
    eq(false, createLink($pdo, baseUrl() . '/abc')['ok'], 'rejects links to itself');
    $r = createLink($pdo, 'https://example.com/ok', null, 'Тест');
    eq(true, $r['ok']);
    ok(preg_match('~^[A-Za-z0-9]{6}$~', $r['code']) === 1, 'code format');
    eq('Тест', $r['title']);
});

test('core: generated codes are unique', function () {
    app();
    $pdo = db();
    $codes = [];
    for ($i = 0; $i < 200; $i++) $codes[] = createLink($pdo, 'https://example.com/u' . $i)['code'];
    eq(200, count(array_unique($codes)));
});

test('core: title is trimmed and capped at 120 chars', function () {
    app();
    $r = createLink(db(), 'https://example.com/t', null, "  " . str_repeat('я', 200) . "\n");
    eq(120, preg_match_all('~.~u', $r['title']));
});
