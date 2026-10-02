<?php
/* "New version available" notice in the admin panel (GitHub latest-release feed, cached daily). */

function withUpdateFeed(?array $release, callable $fn, bool $enabled = true): void {
    $dir  = instance()->dir;
    $feed = $dir . '/storage/test-feed.json';
    $release === null ? @unlink($feed) : file_put_contents($feed, json_encode($release));
    @unlink($dir . '/storage/update-check.json');
    writeConfig($dir, ['update_check' => $enabled, 'update_feed' => $feed]);
    try {
        $fn($feed);
    } finally {
        writeConfig($dir);
        @unlink($feed);
        @unlink($dir . '/storage/update-check.json');
    }
}

test('update: a newer release shows a notice with a link; same or older shows nothing', function () {
    withUpdateFeed(['tag_name' => 'v99.0.0', 'html_url' => 'https://github.com/iDreamSkies/lnks/releases/tag/v99.0.0'], function () {
        $page = (new Client())->login()->get('/admin.php');
        contains('lnks 99.0.0 is available.', $page->body);
        contains('href="https://github.com/iDreamSkies/lnks/releases/tag/v99.0.0"', $page->body);
    });
    app();
    withUpdateFeed(['tag_name' => 'v' . LNKS_VERSION, 'html_url' => 'https://github.com/x'], function () {
        notContains('is available.', (new Client())->login()->get('/admin.php')->body);
    });
    withUpdateFeed(['tag_name' => 'v0.1.0'], function () {
        notContains('is available.', (new Client())->login()->get('/admin.php')->body);
    });
});

test('update: checked at most once a day (cached), failures are silent', function () {
    withUpdateFeed(['tag_name' => 'v99.0.0', 'html_url' => 'https://github.com/iDreamSkies/lnks/releases/tag/v99.0.0'], function ($feed) {
        $c = (new Client())->login();
        contains('99.0.0', $c->get('/admin.php')->body);
        file_put_contents($feed, json_encode(['tag_name' => 'v98.0.0']));
        contains('99.0.0', $c->get('/admin.php')->body, 'cached result used within 24 h');
        $cache = json_decode((string)file_get_contents(instance()->dir . '/storage/update-check.json'), true);
        eq('99.0.0', $cache['version']);
    });
    withUpdateFeed(null, function ($feed) {
        file_put_contents($feed, '{not json');
        $page = (new Client())->login()->get('/admin.php');
        eq(200, $page->status);
        notContains('is available.', $page->body);
    });
    withUpdateFeed(['tag_name' => 'v99.0.0', 'html_url' => 'javascript:alert(1)'], function () {
        $page = (new Client())->login()->get('/admin.php');
        contains('href="https://github.com/iDreamSkies/lnks/releases"', $page->body, 'unexpected URLs replaced');
        notContains('javascript:', $page->body);
    });
});

test('update: disabled check never reads the feed; public pages and redirects never check', function () {
    withUpdateFeed(['tag_name' => 'v99.0.0'], function () {
        notContains('is available.', (new Client())->login()->get('/admin.php')->body);
        ok(!is_file(instance()->dir . '/storage/update-check.json'), 'no cache written when disabled');
    }, false);
    withUpdateFeed(['tag_name' => 'v99.0.0'], function () {
        $l = api('POST', '/api.php', ['url' => 'https://example.com/no-check'])->json();
        (new Client())->get('/');
        (new Client())->get('/' . $l['code']);
        ok(!is_file(instance()->dir . '/storage/update-check.json'), 'only the admin panel checks');
    });
});
