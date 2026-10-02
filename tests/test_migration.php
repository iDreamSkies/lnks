<?php
/* Database migrations: a database created by the `main` branch schema must upgrade without data loss. */

test('migration: upgrades a database from the main branch', function () {
    app();
    $file = sys_get_temp_dir() . '/lnks-mig-' . bin2hex(random_bytes(4)) . '.sqlite';
    $old = new PDO('sqlite:' . $file);
    $old->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $old->exec(file_get_contents(__DIR__ . '/fixtures/schema-main.sql'));
    $old->exec("INSERT INTO links (code, url, clicks_total, status, created_ip, created_at) VALUES ('oldOne', 'https://old.example', 2, 0, '1.2.3.4', '2025-01-01 10:00:00')");
    $old->exec("INSERT INTO clicks (link_id, ts, referrer) VALUES (1, '2025-01-02 11:00:00', 'https://t.co/x'), (1, '2025-01-03 12:00:00', NULL)");
    $old = null;

    $pdo = new PDO('sqlite:' . $file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    migrate($pdo);
    migrate($pdo);   // idempotent

    eq(LNKS_SCHEMA_VERSION, (int)$pdo->query('PRAGMA user_version')->fetchColumn(), 'schema version');
    $l = $pdo->query("SELECT * FROM links WHERE code = 'oldOne'")->fetch();
    eq('https://old.example', $l['url']);
    eq(2, (int)$l['clicks_total']);
    eq(0, (int)$l['status']);
    eq('2025-01-03 12:00:00', $l['last_click_at'], 'last_click_at backfilled');
    eq(2, (int)$pdo->query('SELECT COUNT(*) FROM clicks')->fetchColumn(), 'click log kept');
    eq(null, $l['expires_at'], 'new column expires_at starts empty');
    eq(null, $l['max_clicks'], 'new column max_clicks starts empty');
    $pdo = null;
    @unlink($file);
});
