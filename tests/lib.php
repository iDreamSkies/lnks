<?php
/**
 * lnks — tiny test framework (no PHPUnit).
 * Spins up a throw-away copy of the app on the PHP built-in server and talks to it over HTTP.
 * Needs only the PHP CLI with pdo_sqlite and allow_url_fopen.
 */

const TEST_ADMIN_USER  = 'tester';
const TEST_ADMIN_PASS  = 'secret-pass-123';
const TEST_API_TOKEN   = 'test-token-0123456789abcdef';

$GLOBALS['__tests'] = [];

function test(string $name, callable $fn): void {
    $GLOBALS['__tests'][] = [$name, $fn];
}

final class AssertionFailed extends Exception {}

function ok($cond, string $msg = 'assertion failed'): void {
    if (!$cond) throw new AssertionFailed($msg);
}

function eq($expected, $actual, string $msg = ''): void {
    if ($expected !== $actual) {
        throw new AssertionFailed(($msg ? $msg . ': ' : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function contains(string $needle, string $haystack, string $msg = ''): void {
    if (strpos($haystack, $needle) === false) {
        throw new AssertionFailed(($msg ? $msg . ': ' : '') . 'missing ' . var_export($needle, true));
    }
}

function notContains(string $needle, string $haystack, string $msg = ''): void {
    if (strpos($haystack, $needle) !== false) {
        throw new AssertionFailed(($msg ? $msg . ': ' : '') . 'unexpected ' . var_export($needle, true));
    }
}

/* ── Throw-away instance ─────────────────────────────────────────── */
function rrmdir(string $dir): void {
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    rmdir($dir);
}

function copyApp(string $from, string $to): void {
    $skip = ['.git', 'tests', 'docs', 'config.php'];
    mkdir($to, 0775, true);
    foreach (new DirectoryIterator($from) as $f) {
        if ($f->isDot() || in_array($f->getFilename(), $skip, true)) continue;
        $src = $f->getPathname();
        $dst = $to . '/' . $f->getFilename();
        if ($f->isDir()) {
            copyApp($src, $dst);
        } elseif (!preg_match('~\.sqlite(-\w+)?$~', $f->getFilename())) {
            copy($src, $dst);
        }
    }
}

/** Write config.php for the test instance. $extra overrides keys. */
function writeConfig(string $dir, array $extra = []): void {
    $cfg = array_replace([
        'app_name'        => 'lnks',
        'base_url'        => '',
        'timezone'        => 'UTC',
        'lang'            => 'en',
        'db_path'         => $dir . '/storage/lnks.sqlite',
        'admin_user'      => TEST_ADMIN_USER,
        'admin_pass_hash' => password_hash(TEST_ADMIN_PASS, PASSWORD_BCRYPT, ['cost' => 4]),
        'api_token'       => TEST_API_TOKEN,
        'public_form'     => true,
        'rate_limit'      => ['max' => 1000, 'window_min' => 60],
    ], $extra);
    file_put_contents($dir . '/config.php', "<?php\nreturn " . var_export($cfg, true) . ";\n");
}

final class Instance {
    public string $dir;
    public string $base;
    private $proc;

    public function __construct() {
        $this->dir = sys_get_temp_dir() . '/lnks-test-' . bin2hex(random_bytes(4));
        copyApp(dirname(__DIR__), $this->dir);
        writeConfig($this->dir);

        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int)substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
        fclose($sock);
        $this->base = 'http://127.0.0.1:' . $port;

        $cmd = [PHP_BINARY, '-d', 'display_errors=stderr', '-S', '127.0.0.1:' . $port, '-t', $this->dir, $this->dir . '/router.php'];
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        // Several workers so tests can fire concurrent requests (ignored on Windows)
        $env = array_merge(getenv(), ['PHP_CLI_SERVER_WORKERS' => '4']);
        $this->proc = proc_open($cmd, [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $this->dir . '/server.log', 'w']], $pipes, $this->dir, $env);

        for ($i = 0; $i < 100; $i++) {
            $c = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($c) { fclose($c); return; }
            usleep(50000);
        }
        throw new RuntimeException('Built-in server did not start');
    }

    public function stop(): void {
        if (is_resource($this->proc)) {
            proc_terminate($this->proc);
            proc_close($this->proc);
        }
        rrmdir($this->dir);
    }

    public function serverLog(): string {
        return (string)@file_get_contents($this->dir . '/server.log');
    }
}

function instance(): Instance {
    static $i = null;
    if ($i === null) {
        $i = new Instance();
        register_shutdown_function(fn() => $i->stop());
    }
    return $i;
}

/** Load the app's PHP functions in-process, wired to the test instance's DB. */
function app(): void {
    static $loaded = false;
    if ($loaded) return;
    $_SERVER['SCRIPT_NAME'] = '/tests.php';
    $_SERVER['HTTP_HOST']   = parse_url(instance()->base, PHP_URL_HOST) . ':' . parse_url(instance()->base, PHP_URL_PORT);
    require instance()->dir . '/bootstrap.php';
    $loaded = true;
}

/* ── HTTP client with a cookie jar ───────────────────────────────── */
final class Response {
    public int $status;
    public array $headers;
    public string $body;

    public function __construct(int $status, array $headers, string $body) {
        $this->status = $status;
        $this->headers = $headers;
        $this->body = $body;
    }

    public function header(string $name): ?string {
        return $this->headers[strtolower($name)][0] ?? null;
    }

    public function json(): array {
        $d = json_decode($this->body, true);
        if (!is_array($d)) throw new AssertionFailed('Response is not JSON: ' . substr($this->body, 0, 200));
        return $d;
    }

    public function csrf(): string {
        if (!preg_match('~name="csrf" value="([^"]+)"~', $this->body, $m)) throw new AssertionFailed('No CSRF token in page');
        return html_entity_decode($m[1]);
    }
}

final class Client {
    private array $cookies = [];
    public array $headers = ['User-Agent: Mozilla/5.0 (X11; Linux x86_64) lnks-tests'];

    public function request(string $method, string $path, $body = null, array $headers = []): Response {
        $h = array_merge($this->headers, $headers);
        if ($this->cookies) {
            $h[] = 'Cookie: ' . implode('; ', array_map(fn($k, $v) => $k . '=' . $v, array_keys($this->cookies), $this->cookies));
        }
        if (is_array($body)) {
            $body = http_build_query($body);
            $h[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        $ctx = stream_context_create(['http' => [
            'method'          => $method,
            'header'          => implode("\r\n", $h),
            'content'         => $body ?? '',
            'ignore_errors'   => true,
            'follow_location' => 0,
            'timeout'         => 10,
        ]]);
        $raw = @file_get_contents(instance()->base . $path, false, $ctx);
        $lines = $http_response_header ?? [];
        if (!$lines) throw new AssertionFailed("No response for $method $path");

        preg_match('~^HTTP/\S+\s+(\d+)~', $lines[0], $m);
        $headers = [];
        foreach (array_slice($lines, 1) as $line) {
            if (strpos($line, ':') === false) continue;
            [$k, $v] = array_map('trim', explode(':', $line, 2));
            $headers[strtolower($k)][] = $v;
            if (strtolower($k) === 'set-cookie' && preg_match('~^([^=]+)=([^;]*)~', $v, $c)) {
                if ($c[2] === '' || $c[2] === 'deleted') unset($this->cookies[$c[1]]); else $this->cookies[$c[1]] = $c[2];
            }
        }
        return new Response((int)($m[1] ?? 0), $headers, (string)$raw);
    }

    public function get(string $path, array $headers = []): Response {
        return $this->request('GET', $path, null, $headers);
    }

    public function post(string $path, $body, array $headers = []): Response {
        return $this->request('POST', $path, $body, $headers);
    }

    /** multipart/form-data POST with one file field. */
    public function upload(string $path, array $fields, string $field, string $filename, string $content, array $headers = []): Response {
        $b = '----lnks' . bin2hex(random_bytes(8));
        $body = '';
        foreach ($fields as $k => $v) {
            $body .= "--$b\r\nContent-Disposition: form-data; name=\"$k\"\r\n\r\n$v\r\n";
        }
        $body .= "--$b\r\nContent-Disposition: form-data; name=\"$field\"; filename=\"$filename\"\r\nContent-Type: text/csv\r\n\r\n$content\r\n--$b--\r\n";
        return $this->request('POST', $path, $body, array_merge($headers, ['Content-Type: multipart/form-data; boundary=' . $b]));
    }

    public function hasCookie(string $name): bool {
        return isset($this->cookies[$name]);
    }

    /** Log into the admin panel and return the client for chaining. */
    public function login(): self {
        $t = $this->get('/admin.php')->csrf();
        $r = $this->post('/admin.php', ['csrf' => $t, 'username' => TEST_ADMIN_USER, 'password' => TEST_ADMIN_PASS]);
        eq(302, $r->status, 'admin login');
        return $this;
    }
}

/** Fire $n GET requests at the same time (separate processes) and return their HTTP status codes. */
function parallelGet(string $path, int $n): array {
    $script = 'stream_context_set_default(["http" => ["ignore_errors" => true, "follow_location" => 0, "header" => "User-Agent: Mozilla/5.0"]]);'
        . '@file_get_contents($argv[1]); preg_match("~\\s(\\d{3})\\s~", $http_response_header[0] ?? "", $m); echo $m[1] ?? 0;';
    $procs = [];
    for ($i = 0; $i < $n; $i++) {
        $procs[] = proc_open([PHP_BINARY, '-r', $script, instance()->base . $path], [1 => ['pipe', 'w']], $pipes);
        $outs[] = $pipes[1];
    }
    $codes = [];
    foreach ($procs as $i => $p) {
        $codes[] = (int)stream_get_contents($outs[$i]);
        fclose($outs[$i]);
        proc_close($p);
    }
    return $codes;
}

/** API helper: JSON request with the test token. */
function api(string $method, string $path, ?array $json = null, string $token = TEST_API_TOKEN): Response {
    $h = ['Authorization: Bearer ' . $token];
    if ($json !== null) $h[] = 'Content-Type: application/json';
    return (new Client())->request($method, $path, $json !== null ? json_encode($json) : null, $h);
}

/* ── Runner ──────────────────────────────────────────────────────── */
function runTests(?string $filter = null): int {
    $tty = function_exists('stream_isatty') && @stream_isatty(STDOUT);
    $ok  = $tty ? "\033[32m✓\033[0m" : '✓';
    $bad = fn(string $s) => $tty ? "\033[31m✗ $s\033[0m" : "✗ $s";
    $passed = $failed = 0;
    $start = microtime(true);
    foreach ($GLOBALS['__tests'] as [$name, $fn]) {
        if ($filter !== null && stripos($name, $filter) === false) continue;
        try {
            $fn();
            $passed++;
            echo "  $ok $name\n";
        } catch (Throwable $e) {
            $failed++;
            $where = $e instanceof AssertionFailed ? '' : ' (' . get_class($e) . ' at ' . basename($e->getFile()) . ':' . $e->getLine() . ')';
            echo "  " . $bad($name) . "\n      " . $e->getMessage() . $where . "\n";
        }
    }
    if ($failed) {
        $log = trim(instance()->serverLog());
        $errors = implode("\n", array_filter(explode("\n", $log), fn($l) => preg_match('~PHP (Fatal|Parse|Warning|Notice|Deprecated|Error)~i', $l) === 1));
        if ($errors !== '') echo "\nServer PHP errors:\n$errors\n";
    }
    printf("\n%d passed, %d failed (%.1fs)\n", $passed, $failed, microtime(true) - $start);
    return $failed ? 1 : 0;
}
