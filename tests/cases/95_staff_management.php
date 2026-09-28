<?php
return function (TestEnv $env): void {
    run_test('pharmacy_staff cannot reach staff management', function () use ($env) {
        $jar = $env->tmpDir . '/cookies-staffmgmt-nonadmin.txt';
        $get = http_request('GET', $env->baseUrl . '/admin/login.php', ['cookie_jar' => $jar]);
        $csrf = extract_csrf($get['body']);
        http_request('POST', $env->baseUrl . '/admin/login.php', [
            'cookie_jar' => $jar,
            'body' => ['csrf_token' => $csrf, 'email' => 'staff@example.test', 'password' => 'another-strong-pass'],
        ]);
        $r = http_request('GET', $env->baseUrl . '/admin/staff/', ['cookie_jar' => $jar]);
        assert_equal(403, $r['status']);
    });

    run_test('an admin can create a new staff account', function () use ($env) {
        $jar = $env->cookieJarStaff;
        $get = http_request('GET', $env->baseUrl . '/admin/staff/new.php', ['cookie_jar' => $jar]);
        $csrf = extract_csrf($get['body']);
        $post = http_request('POST', $env->baseUrl . '/admin/staff/new.php', [
            'cookie_jar' => $jar,
            'body' => [
                'csrf_token' => $csrf, 'name' => 'New Hire', 'email' => 'new.hire@example.test',
                'role' => 'pharmacy_staff', 'password' => 'a-fresh-strong-password', 'password_confirm' => 'a-fresh-strong-password',
            ],
        ]);
        assert_equal(302, $post['status']);
        assert_contains('/admin/staff/', $post['location'] ?? '');

        $list = http_request('GET', $env->baseUrl . '/admin/staff/', ['cookie_jar' => $jar]);
        assert_contains('New Hire', $list['body']);
        assert_contains('new.hire@example.test', $list['body']);
    });

    run_test('creating a staff account rejects a duplicate email and a short password', function () use ($env) {
        $jar = $env->cookieJarStaff;
        $get = http_request('GET', $env->baseUrl . '/admin/staff/new.php', ['cookie_jar' => $jar]);
        $csrf = extract_csrf($get['body']);
        $post = http_request('POST', $env->baseUrl . '/admin/staff/new.php', [
            'cookie_jar' => $jar,
            'body' => [
                'csrf_token' => $csrf, 'name' => 'Dupe', 'email' => 'new.hire@example.test',
                'role' => 'pharmacy_staff', 'password' => 'short', 'password_confirm' => 'short',
            ],
        ]);
        assert_equal(200, $post['status']);
        assert_contains('has-error', $post['body']);
    });

    run_test('an admin can deactivate another account, which then cannot sign in', function () use ($env) {
        $newHireId = (int) $env->scalar("SELECT id FROM staff_users WHERE email = 'new.hire@example.test'");
        assert_true($newHireId > 0);

        $jar = $env->cookieJarStaff;
        $get = http_request('GET', $env->baseUrl . '/admin/staff/', ['cookie_jar' => $jar]);
        $csrf = extract_csrf($get['body']);
        $post = http_request('POST', $env->baseUrl . '/admin/staff/', [
            'cookie_jar' => $jar,
            'body' => ['csrf_token' => $csrf, 'staff_id' => $newHireId],
        ]);
        assert_contains('Account deactivated', $post['body']);

        $loginJar = $env->tmpDir . '/cookies-deactivated-login.txt';
        $loginGet = http_request('GET', $env->baseUrl . '/admin/login.php', ['cookie_jar' => $loginJar]);
        $loginCsrf = extract_csrf($loginGet['body']);
        $loginPost = http_request('POST', $env->baseUrl . '/admin/login.php', [
            'cookie_jar' => $loginJar,
            'body' => ['csrf_token' => $loginCsrf, 'email' => 'new.hire@example.test', 'password' => 'a-fresh-strong-password'],
        ]);
        assert_contains('deactivated', $loginPost['body']);
    });

    run_test('an admin cannot deactivate their own account', function () use ($env) {
        $jar = $env->cookieJarStaff;
        $selfId = (int) $env->scalar("SELECT id FROM staff_users WHERE email = 'admin@example.test'");
        $get = http_request('GET', $env->baseUrl . '/admin/staff/', ['cookie_jar' => $jar]);
        $csrf = extract_csrf($get['body']);
        $post = http_request('POST', $env->baseUrl . '/admin/staff/', [
            'cookie_jar' => $jar,
            'body' => ['csrf_token' => $csrf, 'staff_id' => $selfId],
        ]);
        assert_contains('cannot deactivate your own account', $post['body']);
        assert_equal(1, (int) $env->scalar('SELECT active FROM staff_users WHERE id = ?', [$selfId]));
    });

    run_test('an already-logged-in session is booted once its account is deactivated mid-session', function () use ($env) {
        $newHireId = (int) $env->scalar("SELECT id FROM staff_users WHERE email = 'new.hire@example.test'");
        // Reactivate and sign in with a fresh session.
        $env->pdo()->prepare('UPDATE staff_users SET active = 1 WHERE id = ?')->execute([$newHireId]);

        $jar = $env->tmpDir . '/cookies-midsession-deactivate.txt';
        $get = http_request('GET', $env->baseUrl . '/admin/login.php', ['cookie_jar' => $jar]);
        $csrf = extract_csrf($get['body']);
        http_request('POST', $env->baseUrl . '/admin/login.php', [
            'cookie_jar' => $jar,
            'body' => ['csrf_token' => $csrf, 'email' => 'new.hire@example.test', 'password' => 'a-fresh-strong-password'],
        ]);
        $before = http_request('GET', $env->baseUrl . '/admin/gs-requests/', ['cookie_jar' => $jar]);
        assert_equal(200, $before['status'], 'session should be valid right after login');

        (function () use ($env, $newHireId) {
            $env->pdo()->prepare('UPDATE staff_users SET active = 0 WHERE id = ?')->execute([$newHireId]);
        })();

        $after = http_request('GET', $env->baseUrl . '/admin/gs-requests/', ['cookie_jar' => $jar]);
        assert_equal(302, $after['status'], 'require_login() re-checks active status on every request');
        assert_contains('/admin/login.php', $after['location'] ?? '');
    });
};
