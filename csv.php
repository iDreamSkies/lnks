<?php
/**
 * lnks — CSV import / export.
 *
 * Export columns: code,url,title,clicks,status,created_at,last_click_at,expires_at,max_clicks
 * Import accepts those columns (any order, header row required) and the YOURLS columns
 * keyword,url,title,timestamp,ip,clicks. A file with a single column of URLs needs no header.
 * Delimiter (comma, semicolon or tab) and a UTF-8 BOM are detected automatically.
 */

const CSV_COLUMNS    = ['code', 'url', 'title', 'clicks', 'status', 'created_at', 'last_click_at', 'expires_at', 'max_clicks', 'domain'];
const CSV_MAX_BYTES  = 5 * 1024 * 1024;
const CSV_MAX_ROWS   = 50000;

/** Header aliases → internal field. Keys are lower-case, spaces/dashes normalised to "_". */
const CSV_ALIASES = [
    'code' => 'code', 'keyword' => 'code', 'slug' => 'code', 'short' => 'code', 'short_code' => 'code', 'alias' => 'code',
    'url' => 'url', 'long_url' => 'url', 'longurl' => 'url', 'target' => 'url', 'destination' => 'url', 'link' => 'url',
    'title' => 'title', 'name' => 'title',
    'clicks' => 'clicks', 'clicks_total' => 'clicks', 'visits' => 'clicks', 'hits' => 'clicks',
    'status' => 'status', 'active' => 'status', 'enabled' => 'status',
    'created_at' => 'created_at', 'timestamp' => 'created_at', 'date' => 'created_at', 'created' => 'created_at',
    'last_click_at' => 'last_click_at',
    'expires_at' => 'expires_at', 'expires' => 'expires_at', 'expiry' => 'expires_at',
    'max_clicks' => 'max_clicks', 'click_limit' => 'max_clicks',
    'ip' => 'ip', 'created_ip' => 'ip',
    'domain' => 'domain', 'host' => 'domain',
];

/* ── Export ──────────────────────────────────────────────────────── */

/** Neutralise spreadsheet formulas (=, +, -, @ at the start) — CSV injection. */
function csvSafe($v): string {
    $v = (string)$v;
    return $v !== '' && strpos("=+-@\t\r", $v[0]) !== false ? "'" . $v : $v;
}

function csvLine(array $cells): string {
    $out = [];
    foreach ($cells as $c) {
        $c = (string)$c;
        $out[] = preg_match('~[",\r\n;\t]~', $c) || $c !== trim($c) ? '"' . str_replace('"', '""', $c) . '"' : $c;
    }
    return implode(',', $out) . "\r\n";
}

/** Write every link as CSV (UTF-8 with BOM so Excel opens it correctly). */
function exportCsv(PDO $pdo, $out): int {
    fwrite($out, "\xEF\xBB\xBF" . csvLine(CSV_COLUMNS));
    $n = 0;
    $st = $pdo->query('SELECT * FROM links ORDER BY id');
    while ($l = $st->fetch(PDO::FETCH_ASSOC)) {
        fwrite($out, csvLine([
            csvSafe($l['code']), csvSafe($l['url']), csvSafe((string)$l['title']), (int)$l['clicks_total'],
            (int)$l['status'] === 1 ? 'active' : 'disabled', $l['created_at'], (string)$l['last_click_at'],
            (string)$l['expires_at'], $l['max_clicks'] === null ? '' : (int)$l['max_clicks'], (string)$l['domain'],
        ]));
        $n++;
    }
    return $n;
}

function exportFilename(): string {
    return 'lnks-' . gmdate('Y-m-d-Hi') . '.csv';
}

/* ── Parse ───────────────────────────────────────────────────────── */

function csvDelimiter(string $firstLine): string {
    $best = ',';
    $max  = -1;
    foreach ([',', ';', "\t"] as $d) {
        $n = count(str_getcsv($firstLine, $d, '"', ''));
        if ($n > $max) { $max = $n; $best = $d; }
    }
    return $best;
}

/** Undo csvSafe(): a leading apostrophe added in front of a formula character. */
function csvUnsafe(string $v): string {
    return strlen($v) > 1 && $v[0] === "'" && strpos("=+-@\t\r", $v[1]) !== false ? substr($v, 1) : $v;
}

/** Parse a flexible timestamp (created_at / last_click_at); past dates allowed. */
function parseStamp(string $v): ?string {
    $v = trim($v);
    if ($v === '') return null;
    if (preg_match('~^\d{9,11}$~', $v)) return gmdate('Y-m-d H:i:s', (int)$v);   // Unix time
    [$utc, $err] = parseExpiry($v, false);
    return $err ? null : $utc;
}

/**
 * Parse CSV text into normalised rows.
 * @return array{rows: array<int,array>, errors: array<int,array{line:int,error:string}>, format: string, total: int}
 */
function parseCsv(string $text): array {
    $res = ['rows' => [], 'errors' => [], 'format' => 'lnks', 'total' => 0];
    if (strncmp($text, "\xEF\xBB\xBF", 3) === 0) $text = substr($text, 3);
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    if (trim($text) === '') {
        $res['errors'][] = ['line' => 0, 'error' => t('csv.err_empty')];
        return $res;
    }
    if (!preg_match('//u', $text)) {
        $res['errors'][] = ['line' => 0, 'error' => t('csv.err_encoding')];
        return $res;
    }

    $delim = csvDelimiter(strtok($text, "\n"));
    $fh = fopen('php://temp', 'w+');
    fwrite($fh, $text);
    rewind($fh);

    $map  = null;
    $line = 0;
    while (($cells = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
        $line++;
        if ($cells === [null] || implode('', array_map('trim', array_map('strval', $cells))) === '') continue;
        $cells = array_map(fn($c) => trim((string)$c), $cells);

        if ($map === null) {
            // Header row, or a headerless single column of URLs
            if (count($cells) === 1 && isValidUrl($cells[0])) {
                $map = [0 => 'url'];
                $res['format'] = 'urls';
            } else {
                $map = [];
                foreach ($cells as $i => $h) {
                    $key = preg_replace('~[\s-]+~', '_', strtolower($h));
                    if (isset(CSV_ALIASES[$key])) $map[$i] = CSV_ALIASES[$key];
                }
                if (!in_array('url', $map, true)) {
                    $res['errors'][] = ['line' => $line, 'error' => t('csv.err_header')];
                    fclose($fh);
                    return $res;
                }
                $lower = array_map('strtolower', $cells);
                if (in_array('keyword', $lower, true)) $res['format'] = 'yourls';
                continue;
            }
        }

        $res['total']++;
        if ($res['total'] > CSV_MAX_ROWS) {
            $res['errors'][] = ['line' => $line, 'error' => t('csv.err_rows', ['n' => CSV_MAX_ROWS])];
            break;
        }
        $raw = [];
        foreach ($map as $i => $field) {
            $v = csvUnsafe($cells[$i] ?? '');
            // phpMyAdmin writes SQL NULL as the text "NULL"
            $raw[$field] = $v === 'NULL' && $field !== 'url' && $field !== 'code' ? '' : $v;
        }
        [$row, $error] = normalizeImportRow($raw);
        if ($error !== null) {
            $res['errors'][] = ['line' => $line, 'error' => $error, 'url' => cut($raw['url'] ?? '', 80, '…')];
        } else {
            $row['line'] = $line;
            $res['rows'][] = $row;
        }
    }
    fclose($fh);
    return $res;
}

/** Validate one imported row. @return array{0: ?array, 1: ?string} */
function normalizeImportRow(array $r): array {
    $url = trim($r['url'] ?? '');
    if (!isValidUrl($url))  return [null, t('err.invalid_url')];
    if (strlen($url) > 2048) return [null, t('err.too_long')];
    if (isOwnUrl($url)) return [null, t('err.self')];
    [$domain, $err] = parseDomain($r['domain'] ?? '');
    if ($err) return [null, $err];

    $code = trim($r['code'] ?? '');
    if ($code !== '' && !isValidCode($code)) return [null, t('csv.err_code', ['code' => cut($code, 40, '…')])];

    $clicks = trim($r['clicks'] ?? '');
    if ($clicks !== '' && !preg_match('~^\d{1,10}$~', $clicks)) return [null, t('csv.err_clicks')];

    $status = strtolower(trim($r['status'] ?? ''));
    if ($status !== '' && !in_array($status, ['active', 'disabled', '1', '0', 'true', 'false', 'yes', 'no', 'on', 'off'], true)) {
        return [null, t('csv.err_status')];
    }

    [$expires, $err] = parseExpiry($r['expires_at'] ?? '', false);   // past is fine: the link imports as expired
    if ($err) return [null, $err];
    [$max, $err] = parseMaxClicks($r['max_clicks'] ?? '');
    if ($err) return [null, $err];

    $ip = trim($r['ip'] ?? '');
    return [[
        'code'          => $code,
        'url'           => $url,
        'title'         => cleanTitle($r['title'] ?? null),
        'clicks'        => (int)$clicks,
        'status'        => in_array($status, ['disabled', '0', 'false', 'no', 'off'], true) ? 0 : 1,
        'created_at'    => parseStamp($r['created_at'] ?? '') ?? now(),
        'last_click_at' => parseStamp($r['last_click_at'] ?? ''),
        'expires_at'    => $expires,
        'max_clicks'    => $max,
        'ip'            => filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null,
        'domain'        => $domain,
    ], null];
}

/* ── Plan & import ───────────────────────────────────────────────── */

/**
 * Mark each row as new or duplicate (code already in the database or earlier in the file).
 * @return array{new: array, duplicates: array}
 */
function planImport(PDO $pdo, array $rows): array {
    $plan = ['new' => [], 'duplicates' => []];
    $seen = [];
    $st = $pdo->prepare('SELECT 1 FROM links WHERE code = :c');
    foreach ($rows as $r) {
        if ($r['code'] !== '') {
            $key = $r['code'];
            $st->execute([':c' => $key]);
            $exists = (bool)$st->fetchColumn();
            $st->closeCursor();
            if ($exists || isset($seen[$key])) {
                $plan['duplicates'][] = $r + ['reason' => $exists ? 'exists' : 'file'];
                continue;
            }
            $seen[$key] = true;
        }
        $plan['new'][] = $r;
    }
    return $plan;
}

/** Insert the planned rows in one transaction. Rows without a code get a generated one. */
function runImport(PDO $pdo, array $rows): array {
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $plan = planImport($pdo, $rows);   // re-check under the write lock
        $ins = $pdo->prepare('INSERT INTO links (code, url, title, clicks_total, last_click_at, expires_at, max_clicks, status, created_ip, domain, created_at)
                              VALUES (:c, :u, :t, :n, :lc, :e, :m, :s, :ip, :d, :a)');
        // Rows with an explicit code first, so a generated code can never take a code used later in the file
        $new = $plan['new'];
        usort($new, fn($a, $b) => ($a['code'] === '') <=> ($b['code'] === ''));
        foreach ($new as $r) {
            $ins->execute([
                ':c' => $r['code'] !== '' ? $r['code'] : generateCode($pdo), ':u' => $r['url'], ':t' => $r['title'],
                ':n' => $r['clicks'], ':lc' => $r['last_click_at'], ':e' => $r['expires_at'], ':m' => $r['max_clicks'],
                ':s' => $r['status'], ':ip' => $r['ip'], ':d' => $r['domain'] ?? null, ':a' => $r['created_at'],
            ]);
        }
        $pdo->exec('COMMIT');
    } catch (Throwable $ex) {
        $pdo->exec('ROLLBACK');
        throw $ex;
    }
    return ['imported' => count($plan['new']), 'duplicates' => count($plan['duplicates'])];
}

/* ── Pending import (admin preview → confirm) ────────────────────── */

function pendingImportPath(string $id): string {
    return dirname(cfg()['db_path']) . '/import-' . $id . '.json';
}

/** Store parsed rows between the preview and the confirmation; returns an id. */
function savePendingImport(array $rows): string {
    foreach (glob(dirname(cfg()['db_path']) . '/import-*.json') ?: [] as $old) {
        if (filemtime($old) < time() - 3600) @unlink($old);
    }
    $id = bin2hex(random_bytes(12));
    file_put_contents(pendingImportPath($id), json_encode($rows, JSON_UNESCAPED_UNICODE), LOCK_EX);
    return $id;
}

function takePendingImport(string $id): ?array {
    if (!preg_match('~^[a-f0-9]{24}$~', $id)) return null;
    $file = pendingImportPath($id);
    if (!is_file($file)) return null;
    $rows = json_decode((string)file_get_contents($file), true);
    @unlink($file);
    return is_array($rows) ? $rows : null;
}
