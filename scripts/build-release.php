<?php
/**
 * lnks — build a release archive.
 *
 *   php scripts/build-release.php                  build dist/lnks-X.Y.Z.zip for the version in layout.php
 *   php scripts/build-release.php v2.0.0           …and fail unless the tag matches that version
 *   php scripts/build-release.php --out=/tmp/rel   write into another directory
 *
 * Output: lnks-X.Y.Z.zip (one top-level folder lnks-X.Y.Z/), lnks-X.Y.Z.zip.sha256 and
 * release-notes.md (the CHANGELOG section of this version, used for the GitHub release text).
 * The archive holds only what runs on a server: no .git, tests, docs, CI files, config or data.
 * Needs the zip extension (developer tool — the app itself does not).
 */
if (PHP_SAPI !== 'cli') exit("CLI only\n");

$root = dirname(__DIR__);
$out  = $root . '/dist';
$tag  = null;
foreach (array_slice($argv, 1) as $arg) {
    if (strncmp($arg, '--out=', 6) === 0) $out = rtrim(substr($arg, 6), '/');
    else $tag = $arg;
}

function fail(string $msg): void {
    fwrite(STDERR, "error: $msg\n");
    exit(1);
}

if (!class_exists('ZipArchive')) fail('the zip extension is required to build a release');

// Version: layout.php is the single source of truth
if (!preg_match("~const LNKS_VERSION = '(\\d+\\.\\d+\\.\\d+)';~", (string)file_get_contents($root . '/layout.php'), $m)) {
    fail('LNKS_VERSION not found in layout.php');
}
$version = $m[1];
if ($tag !== null && ltrim($tag, 'v') !== $version) fail("tag $tag does not match LNKS_VERSION $version");

// Release notes: the "## [X.Y.Z]" section of CHANGELOG.md
$changelog = (string)file_get_contents($root . '/CHANGELOG.md');
if (!preg_match('~^## \[' . preg_quote($version, '~') . '\][^\n]*\n(.*?)(?=^## \[|^\[[^\]]+\]: |\z)~ms', $changelog, $cm)) {
    fail("CHANGELOG.md has no section for $version");
}
$notes = trim($cm[1]);

// What goes in: everything except development files, local config and data
$exclude = [
    '~^\.git(/|$)~', '~^\.github/~', '~^\.gitignore$~', '~^tests/~', '~^docs/~', '~^dist/~',
    '~^scripts/build-release\.php$~', '~^config\.php$~', '~^server\.log$~',
    '~^storage/(?!\.htaccess$)~',   // keep only storage/.htaccess (the folder itself must exist)
];
$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!$f->isFile()) continue;
    $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
    foreach ($exclude as $re) if (preg_match($re, $rel)) continue 2;
    $files[] = $rel;
}
sort($files);
foreach (['index.php', 'admin.php', 'api.php', 'install.php', 'bootstrap.php', '.htaccess', 'storage/.htaccess', 'LICENSE', 'CHANGELOG.md'] as $must) {
    if (!in_array($must, $files, true)) fail("required file missing from the release: $must");
}

if (!is_dir($out) && !mkdir($out, 0775, true)) fail("cannot create $out");
$name = "lnks-$version";
$zipPath = "$out/$name.zip";
@unlink($zipPath);
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE) !== true) fail("cannot write $zipPath");
$zip->addEmptyDir($name);
foreach ($files as $rel) {
    $zip->addFile("$root/$rel", "$name/$rel");
}
$zip->close();

$hash = hash_file('sha256', $zipPath);
file_put_contents("$zipPath.sha256", "$hash  $name.zip\n");
file_put_contents("$out/release-notes.md", $notes . "\n\n**SHA-256** `$name.zip`: `$hash`\n");

printf("%s: %d files, %.0f KB\nsha256 %s\n", $zipPath, count($files), filesize($zipPath) / 1024, $hash);
