<?php
/**
 * lnks — optional IPv4 → country lookup without external services.
 *
 * The table is built by scripts/build-geoip.php from the public RIR "delegated" statistics
 * (AFRINIC, APNIC, ARIN, LACNIC, RIPE NCC). It records the country an address block is
 * registered to — good enough for country-level stats, not precise geolocation.
 * File format: sorted fixed-size records of 6 bytes — range start (uint32, big endian) + ISO country
 * code (2 ASCII letters, "ZZ" = unknown). A lookup is a binary search with fseek: no memory load.
 * Disabled unless config 'geoip' is true and the file exists. IPv6 visitors are not resolved.
 */

const GEO_RECORD = 6;

function geoPath(): string {
    return dirname(cfg()['db_path']) . '/geoip-v4.bin';
}

function geoEnabled(): bool {
    return !empty(cfg()['geoip']) && is_file(geoPath());
}

/** ISO country code for an IPv4 address, or null. */
function geoCountry(string $ip, ?string $file = null): ?string {
    $file = $file ?? geoPath();
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return null;
    $fh = @fopen($file, 'rb');
    if (!$fh) return null;
    $n = (int)(filesize($file) / GEO_RECORD);
    $target = sprintf('%u', ip2long($ip));
    $lo = 0;
    $hi = $n - 1;
    $found = null;
    while ($lo <= $hi) {   // last record whose start <= ip
        $mid = intdiv($lo + $hi, 2);
        fseek($fh, $mid * GEO_RECORD);
        $rec = fread($fh, GEO_RECORD);
        $start = sprintf('%u', unpack('N', substr($rec, 0, 4))[1]);
        if ((float)$start <= (float)$target) {
            $found = substr($rec, 4, 2);
            $lo = $mid + 1;
        } else {
            $hi = $mid - 1;
        }
    }
    fclose($fh);
    return $found !== null && $found !== 'ZZ' && preg_match('~^[A-Z]{2}$~', $found) ? $found : null;
}

/**
 * Build the binary table from RIR delegated-stats text (one or more files concatenated).
 * Lines look like: ripencc|NL|ipv4|193.0.0.0|2048|19930901|allocated
 * @return int number of records written
 */
function geoBuild(iterable $lines, string $out): int {
    $ranges = [];
    foreach ($lines as $line) {
        $p = explode('|', trim($line));
        if (count($p) < 7 || $p[2] !== 'ipv4' || !preg_match('~^[A-Z]{2}$~', $p[1])) continue;
        if (!in_array($p[6], ['allocated', 'assigned'], true)) continue;
        $start = ip2long($p[3]);
        $count = (int)$p[4];
        if ($start === false || $count < 1) continue;
        $s = (float)sprintf('%u', $start);
        $ranges[] = [$s, $s + $count, $p[1]];
    }
    usort($ranges, fn($a, $b) => $a[0] <=> $b[0]);

    // Turn ranges into "start → country" records, with ZZ for gaps; merge neighbours with the same country
    $records = [];
    $cursor = 0.0;
    foreach ($ranges as [$s, $e, $cc]) {
        if ($e <= $cursor) continue;           // fully covered by a previous range
        if ($s > $cursor) $records[] = [$cursor, 'ZZ'];
        $records[] = [max($s, $cursor), $cc];
        $cursor = $e;
    }
    if ($cursor < 4294967296.0) $records[] = [$cursor, 'ZZ'];

    $fh = fopen($out . '.tmp', 'wb');
    $n = 0;
    $prev = null;
    foreach ($records as [$s, $cc]) {
        if ($cc === $prev) continue;
        fwrite($fh, pack('N', (int)$s) . $cc);
        $prev = $cc;
        $n++;
    }
    fclose($fh);
    rename($out . '.tmp', $out);
    return $n;
}
