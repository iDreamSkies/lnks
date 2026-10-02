<?php
/* QR codes: generator is vendored and wired into pages. Rendering itself is client-side
   (verified manually by decoding the generated PNG/SVG, see PR description). */

test('qr: vendored generator and its MIT license are shipped', function () {
    $r = (new Client())->get('/public/vendor/qrcode.js');
    eq(200, $r->status);
    contains('Kazuhiko Arase', $r->body);
    contains('MIT license', $r->body);
    $lic = (string)file_get_contents(dirname(__DIR__) . '/public/vendor/LICENSE-qrcode-generator.txt');
    contains('Permission is hereby granted', $lic);
});

test('qr: admin list and stats page have QR buttons', function () {
    $l = api('POST', '/api.php', ['url' => 'https://example.com/qr'])->json();
    $c = (new Client())->login();
    $list = $c->get('/admin.php?q=' . $l['code']);
    contains('data-qr="' . $l['short_url'] . '"', $list->body);
    contains('data-qr-name="' . $l['code'] . '"', $list->body);
    contains('src="/public/vendor/qrcode.js', $list->body);
    contains('data-qr="' . $l['short_url'] . '"', $c->get('/admin.php?id=' . $l['id'])->body);
});

test('qr: public page loads the generator only after shortening', function () {
    $c = new Client();
    $home = $c->get('/');
    notContains('qrcode.js', $home->body);
    $r = $c->post('/', ['csrf' => $home->csrf(), 'url' => 'https://example.com/qr-home']);
    contains('qrcode.js', $r->body);
    contains('data-qr="', $r->body);
});

test('qr: dialog labels are translated', function () {
    $c = new Client();
    contains('data-qr-png="Download PNG"', $c->get('/')->body);
    contains('data-qr-png="Скачать PNG"', $c->get('/?lang=ru')->body);
});
