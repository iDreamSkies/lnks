<?php
/**
 * lnks — test runner.
 *   php tests/run.php            run everything
 *   php tests/run.php qr         run tests whose name contains "qr"
 */
if (PHP_SAPI !== 'cli') exit("CLI only\n");
if (!extension_loaded('pdo_sqlite')) exit("pdo_sqlite is required\n");

error_reporting(E_ALL);
ini_set('display_errors', '1');
set_error_handler(function ($no, $str, $file, $line) {
    if (!(error_reporting() & $no)) return false;   // respect the @ operator
    throw new ErrorException($str, 0, $no, $file, $line);
});

require __DIR__ . '/lib.php';
foreach (glob(__DIR__ . '/test_*.php') as $file) require $file;

echo 'lnks tests · PHP ' . PHP_VERSION . "\n\n";
exit(runTests($argv[1] ?? null));
