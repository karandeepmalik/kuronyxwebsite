<?php
return function (TestEnv $env): void {
    run_test('setup.php without a token is forbidden', function () use ($env) {
        $r = http_request('GET', $env->baseUrl . '/admin/setup.php');
        assert_equal(403, $r['status']);
    });

    run_test('setup.php with the right token shows the create-admin form', function () use ($env) {
        $r = http_request('GET', $env->baseUrl . '/admin/setup.php?token=test-setup-token', ['cookie_jar' => $env->tmpDir . '/cookies-setup.txt']);
        assert_equal(200, $r['status']);
        assert_contains('Create the first admin account', $r['body']);
    });

    run_test('setup.php creates the first admin account', function () use ($env) {
        $jar = $env->tmpDir . '/cookies-setup.txt';
        $get = http_request('GET', $env->baseUrl . '/admin/setup.php?token=test-setup-token', ['cookie_jar' => $jar]);
        $csrf = extract_csrf($get['body']);
        $post = http_request('POST', $env->baseUrl . '/admin/setup.php?token=test-setup-token', [
            'cookie_jar' => $jar,
            'body' => [
                'csrf_token' => $csrf,
                'token' => 'test-setup-token',
                'name' => 'Test Admin',
                'email' => 'admin@example.test',
                'password' => 'correct horse battery staple',
                'password_confirm' => 'correct horse battery staple',
            ],
        ]);
        assert_equal(302, $post['status']);
        assert_contains('/admin/login.php', $post['location'] ?? '');
        $count = (int) $env->pdo()->query("SELECT COUNT(*) FROM staff_users WHERE email = 'admin@example.test'")->fetchColumn();
        assert_equal(1, $count, 'admin account should exist in DB');
    });

    run_test('setup.php refuses to run a second time', function () use ($env) {
        $r = http_request('GET', $env->baseUrl . '/admin/setup.php?token=test-setup-token', ['cookie_jar' => $env->tmpDir . '/cookies-setup2.txt']);
        assert_equal(200, $r['status']);
        assert_contains('Setup already completed', $r['body']);
    });

    run_test('admin pages redirect anonymous visitors to login', function () use ($env) {
        $r = http_request('GET', $env->baseUrl . '/admin/gs-requests/', ['cookie_jar' => $env->tmpDir . '/cookies-anon1.txt']);
        assert_equal(302, $r['status']);
        assert_contains('/admin/login.php', $r['location'] ?? '');
    });

    run_test('login rejects a wrong password', function () use ($env) {
        $jar = $env->tmpDir . '/cookies-badlogin.txt';
        $get = http_request('GET', $env->baseUrl . '/admin/login.php', ['cookie_jar' => $jar]);
        $csrf = extract_csrf($get['body']);
        $post = http_request('POST', $env->baseUrl . '/admin/login.php', [
            'cookie_jar' => $jar,
            'body' => ['csrf_token' => $csrf, 'email' => 'admin@example.test', 'password' => 'wrong-password'],
        ]);
        assert_contains('Invalid email or password', $post['body']);
    });

    run_test('repeated failed logins lock the identifier out', function () use ($env) {
        $jar = $env->tmpDir . '/cookies-lockout.txt';
        for ($i = 0; $i < 8; $i++) {
            $get = http_request('GET', $env->baseUrl . '/admin/login.php', ['cookie_jar' => $jar]);
            $csrf = extract_csrf($get['body']);
            http_request('POST', $env->baseUrl . '/admin/login.php', [
                'cookie_jar' => $jar,
                'body' => ['csrf_token' => $csrf, 'email' => 'lockout-test@example.test', 'password' => 'wrong'],
            ]);
        }
        $get = http_request('GET', $env->baseUrl . '/admin/login.php', ['cookie_jar' => $jar]);
        $csrf = extract_csrf($get['body']);
        $post = http_request('POST', $env->baseUrl . '/admin/login.php', [
            'cookie_jar' => $jar,
            'body' => ['csrf_token' => $csrf, 'email' => 'lockout-test@example.test', 'password' => 'wrong'],
        ]);
        assert_contains('Too many failed attempts', $post['body']);
    });

    run_test('login with a forged csrf token is rejected', function () use ($env) {
        $jar = $env->tmpDir . '/cookies-csrf.txt';
        http_request('GET', $env->baseUrl . '/admin/login.php', ['cookie_jar' => $jar]);
        $post = http_request('POST', $env->baseUrl . '/admin/login.php', [
            'cookie_jar' => $jar,
            'body' => ['csrf_token' => 'not-the-real-token', 'email' => 'admin@example.test', 'password' => 'correct horse battery staple'],
        ]);
        assert_contains('session expired', $post['body']);
    });

    run_test('login succeeds with correct credentials and reaches the admin area', function () use ($env) {
        $jar = $env->cookieJarStaff;
        $get = http_request('GET', $env->baseUrl . '/admin/login.php', ['cookie_jar' => $jar]);
        $csrf = extract_csrf($get['body']);
        $post = http_request('POST', $env->baseUrl . '/admin/login.php', [
            'cookie_jar' => $jar,
            'body' => ['csrf_token' => $csrf, 'email' => 'admin@example.test', 'password' => 'correct horse battery staple'],
        ]);
        assert_equal(302, $post['status']);
        assert_contains('/admin/gs-requests/', $post['location'] ?? '');

        $home = http_request('GET', $env->baseUrl . '/admin/gs-requests/', ['cookie_jar' => $jar]);
        assert_equal(200, $home['status']);
        assert_contains('GS-441524 Requests', $home['body']);
    });
};
