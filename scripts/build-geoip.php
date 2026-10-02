<?php
/**
 * lnks — build the optional IPv4 → country table for click statistics.
 *
 *   php scripts/build-geoip.php                 download the five RIR "delegated" files and build
 *   php scripts/build-geoip.php file1 file2 …   build from files you downloaded yourself
 *
 * Writes storage/geoip-v4.bin (next to the database). Then set 'geoip' => true in config.php.
 * Data: public registration statistics of AFRINIC, APNIC, ARIN, LACNIC and RIPE NCC — the country
 * an address block is registered to. Re-run monthly to stay current. No files are downloaded at runtime.
 */
if (PHP_SAPI !== 'cli') exit("CLI only\n");

$root = dirname(__DIR__);
if (is_file($root . '/config.php')) {
    $_SERVER['SCRIPT_NAME'] = 'build-geoip.php';
    require $root . '/bootstrap.php';
    $out = geoPath();
} else {
    require $root . '/geo.php';
    $out = $root . '/storage/geoip-v4.bin';
}

const RIR_SOURCES = [
    'https://ftp.afrinic.net/pub/stats/afrinic/delegated-afrinic-extended-latest',
    'https://ftp.apnic.net/stats/apnic/delegated-apnic-extended-latest',
    'https://ftp.arin.net/pub/stats/arin/delegated-arin-extended-latest',
    'https://ftp.lacnic.net/pub/stats/lacnic/delegated-lacnic-extended-latest',
    'https://ftp.ripe.net/pub/stats/ripencc/delegated-ripencc-extended-latest',
];

$sources = array_slice($argv, 1) ?: RIR_SOURCES;
$lines = (function () use ($sources) {
    foreach ($sources as $src) {
        fwrite(STDERR, "reading $src\n");
        $fh = @fopen($src, 'r', false, stream_context_create(['http' => ['timeout' => 60]]));
        if (!$fh) {
            fwrite(STDERR, "  failed — download it manually and pass the file path\n");
            exit(1);
        }
        while (($line = fgets($fh)) !== false) yield $line;
        fclose($fh);
    }
})();

$n = geoBuild($lines, $out);
printf("%s: %d ranges, %.0f KB\n", $out, $n, filesize($out) / 1024);
echo "Now set 'geoip' => true in config.php.\n";
