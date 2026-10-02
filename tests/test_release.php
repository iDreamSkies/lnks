<?php
/* Release archive built by scripts/build-release.php. */

function buildRelease(array $args): array {
    $out = sys_get_temp_dir() . '/lnks-rel-' . bin2hex(random_bytes(4));
    $cmd = array_merge([PHP_BINARY, dirname(__DIR__) . '/scripts/build-release.php', '--out=' . $out], $args);
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    return [proc_close($p), $out, $stdout . $stderr];
}

test('release: archive contains the app and nothing private or dev-only', function () {
    if (!class_exists('ZipArchive')) throw new AssertionFailed('zip extension needed for this test (CI installs it)');
    preg_match("~LNKS_VERSION = '([\\d.]+)'~", (string)file_get_contents(dirname(__DIR__) . '/layout.php'), $m);
    $v = $m[1];
    [$code, $out, $log] = buildRelease(['v' . $v]);
    eq(0, $code, $log);
    $zip = new ZipArchive();
    eq(true, $zip->open("$out/lnks-$v.zip"));
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) $names[] = $zip->getNameIndex($i);
    $zip->close();
    foreach (['index.php', 'admin.php', 'api.php', 'install.php', '.htaccess', 'storage/.htaccess', 'public/vendor/qrcode.js',
              'scripts/build-geoip.php', 'lang/ru.php', 'LICENSE', 'CHANGELOG.md', 'README.ru.md'] as $f) {
        ok(in_array("lnks-$v/$f", $names, true), "contains $f");
    }
    foreach ($names as $n) {
        ok(!preg_match('~/(\.git|\.github|tests|docs|dist)/|/config\.php$|\.sqlite|build-release\.php$|/storage/(?!\.htaccess$).+~', $n), "excludes $n");
    }
    $sha = trim((string)file_get_contents("$out/lnks-$v.zip.sha256"));
    eq(hash_file('sha256', "$out/lnks-$v.zip") . "  lnks-$v.zip", $sha);
    $notes = (string)file_get_contents("$out/release-notes.md");
    contains('### Added', $notes, 'notes come from the CHANGELOG section');
    notContains('## [', $notes, 'only this version');
    rrmdir($out);
});

test('release: refuses a tag that does not match the version', function () {
    if (!class_exists('ZipArchive')) throw new AssertionFailed('zip extension needed for this test (CI installs it)');
    [$code, $out, $log] = buildRelease(['v0.0.1']);
    eq(1, $code);
    contains('does not match', $log);
    ok(!is_file("$out/lnks-0.0.1.zip"));
});
