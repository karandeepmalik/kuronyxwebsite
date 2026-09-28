<?php
return function (TestEnv $env): void {
    run_test('GET / returns 200', function () use ($env) {
        $r = http_request('GET', $env->baseUrl . '/index.html');
        assert_equal(200, $r['status']);
        assert_contains('Kuronyx', $r['body']);
    });

    $pages = [
        '/about/index.php'              => 'The dark matter',
        '/for-veterinarians/index.php'  => 'GS-441524',
        '/for-cat-owners/index.php'     => 'GS-441524',
        '/contact-us/index.php'         => 'Write to us',
        '/dispatches/index.php'         => 'Dispatches',
    ];
    foreach ($pages as $path => $needle) {
        run_test("GET {$path} returns 200", function () use ($env, $path, $needle) {
            $r = http_request('GET', $env->baseUrl . $path);
            assert_equal(200, $r['status'], "unexpected status for {$path}");
            assert_contains($needle, $r['body']);
        });
    }
};
