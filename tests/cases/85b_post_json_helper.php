<?php
// The enquiry and newsletter forms used to call res.json() directly: when the server answered with anything that is not
// JSON (a PHP error page, a 500, a host-level 413 or rate-limit page) it threw, and the visitor saw the browser's own
// parser message ("Unexpected token '<', ..." as the form status); a dropped connection showed "Failed to fetch". They now
// go through KuronyxPost.send() (public_html/js/post-json.js), which never throws and falls back to a plain message that
// says what to do (email hello@kuronyx.in). The helper's logic runs under Node with a stub fetch; the pages are checked
// statically (no browser here).
return function (TestEnv $env): void {
    run_test('KuronyxPost.send turns every kind of bad server answer into a plain message (runs the helper under Node)', function () use ($env) {
        $node = trim((string) @shell_exec('node --version 2>&1'));
        if (!preg_match('/^v\d+/', $node)) skip_test('Node.js is not installed here: the helper\'s logic is not exercised (it is checked in a browser instead)');

        $out = (string) shell_exec('node ' . escapeshellarg($env->root . '/tests/lib/post_json_test.js') . ' 2>&1');
        $results = json_decode($out, true);
        assert_true(is_array($results), "the Node test did not return JSON: {$out}");
        assert_true(count($results) >= 18, 'expected every scenario to be reported');
        $failed = array_filter($results, fn ($r) => !$r['ok']);
        assert_equal([], array_values(array_map(fn ($r) => $r['name'] . ' -> ' . $r['detail'], $failed)), 'these scenarios fail');
    });

    run_test('the enquiry form and the newsletter form go through the helper; none calls res.json() on the endpoint any more', function () use ($env) {
        foreach (['index.html' => '/php/send-enquiry.php', 'js/subscribe.js' => '/php/send-welcome.php'] as $file => $endpoint) {
            $src = file_get_contents($env->root . '/public_html/' . $file);
            assert_contains("KuronyxPost.send(", $src, "{$file} must use the helper");
            assert_contains($endpoint, $src, "{$file} still posts to {$endpoint}");
            assert_true(strpos($src, 'await res.json()') === false && strpos($src, 'res.json()') === false, "{$file} must not parse the response itself (that is what threw the raw parser error)");
            assert_true(strpos($src, 'fetch(') === false, "{$file} must not call fetch() for the form itself (the helper does)");
        }
    });

    run_test('every page that uses the helper loads it (before the newsletter script that needs it)', function () use ($env) {
        $pages = [
            'index.html'           => '<script src="js/post-json.js"></script>',
            'journal/index.php'    => '<script src="/js/post-json.js"></script>',
        ];
        foreach ($pages as $file => $tag) {
            $src = file_get_contents($env->root . '/public_html/' . $file);
            assert_contains($tag, $src, "{$file} must load js/post-json.js");
            if (strpos($src, 'subscribe.js') !== false) {
                assert_true(strpos($src, 'post-json.js') < strpos($src, 'subscribe.js'), "{$file}: post-json.js must come before subscribe.js");
            }
        }
        // Every page that loads subscribe.js is in the list above (a new one would otherwise break its newsletter form).
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($env->root . '/public_html', FilesystemIterator::SKIP_DOTS)) as $f) {
            if (!in_array($f->getExtension(), ['php', 'html'], true)) continue;
            if (strpos(file_get_contents($f->getPathname()), 'subscribe.js') === false) continue;
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($env->root . '/public_html') + 1));
            assert_true(isset($pages[$rel]), "{$rel} loads subscribe.js but is not checked for post-json.js");
        }
    });

    run_test('the helper is served as a script, and the Home page still offers the same address', function () use ($env) {
        $r = http_request('GET', $env->baseUrl . '/js/post-json.js');
        assert_equal(200, $r['status']);
        assert_contains('window.KuronyxPost', $r['body']);
        $page = file_get_contents($env->root . '/public_html/index.html');
        assert_contains('href="mailto:hello@kuronyx.in"', $page, 'the page shows the address the fallback message refers to');
        assert_contains('src="js/post-json.js"', $page);
    });
};
