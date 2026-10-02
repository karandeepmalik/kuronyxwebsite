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

    run_test('setup.php rejects an over-long name/email without permanently locking the page', function () use ($env) {
        // Regression test for the setup-lockout bug: setup_lock and the staff_users
        // insert used to be two separate statements, so if the insert failed for any
        // reason (e.g. a name/email too long for its column — neither was length-checked
        // before), the lock had already committed on its own, permanently locking this
        // page with no admin ever created. Both are now one transaction, and the length
        // is also validated up front — this proves an over-long submission is rejected
        // cleanly (not a DB exception) and, critically, that the page is still usable
        // for a real attempt right afterward (the next test does exactly that).
        $jar = $env->tmpDir . '/cookies-setup-overlong.txt';
        $get = http_request('GET', $env->baseUrl . '/admin/setup.php?token=test-setup-token', ['cookie_jar' => $jar]);
        $csrf = extract_csrf($get['body']);
        $post = http_request('POST', $env->baseUrl . '/admin/setup.php?token=test-setup-token', [
            'cookie_jar' => $jar,
            'body' => [
                'csrf_token' => $csrf,
                'token' => 'test-setup-token',
                'name' => str_repeat('a', 151),
                'email' => 'overlong@example.test',
                'password' => 'correct horse battery staple',
                'password_confirm' => 'correct horse battery staple',
            ],
        ]);
        assert_equal(200, $post['status']);
        assert_contains('has-error', $post['body']);
        assert_equal(0, (int) $env->pdo()->query('SELECT COUNT(*) FROM staff_users')->fetchColumn(), 'no account should have been created');
        assert_equal(0, (int) $env->pdo()->query('SELECT COUNT(*) FROM setup_lock')->fetchColumn(), 'setup_lock must not have been claimed by a failed attempt');

        // The page must still be usable — confirms this failed attempt didn't leave
        // setup_lock claimed with no admin to show for it.
        $recheck = http_request('GET', $env->baseUrl . '/admin/setup.php?token=test-setup-token', ['cookie_jar' => $jar]);
        assert_contains('Create the first admin account', $recheck['body'], 'setup must still be usable after a failed attempt');
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
        // Clean up so this test's own 9 attempts don't eat into the per-IP budget the
        // next test depends on.
        $env->pdo()->prepare("DELETE FROM login_attempts WHERE identifier LIKE 'lockout-test@example.test|%'")->execute();
        $env->pdo()->prepare("DELETE FROM rate_limit_hits WHERE bucket = 'login_ip:127.0.0.1'")->execute();
    });

    run_test('repeated login attempts from the same IP across many different accounts are throttled', function () use ($env) {
        // Regression test for the login-throttling gap: the per-email lockout above has no
        // visibility into one source spraying short password lists across many different
        // accounts — each account's own counter looks fine in isolation. This exhausts the
        // separate per-IP cap instead, using a different nonexistent email on every attempt
        // so the per-email lockout (a different mechanism, tested above) never kicks in
        // first and masks what's actually being tested here.
        require_once $env->root . '/public_html/includes/auth.php';
        $bucket = 'login_ip:127.0.0.1';
        $remaining = LOGIN_IP_MAX_ATTEMPTS - (int) $env->scalar('SELECT COUNT(*) FROM rate_limit_hits WHERE bucket = ?', [$bucket]);
        assert_true($remaining > 0, 'expected some per-IP login budget left to exhaust');

        $jar = $env->tmpDir . '/cookies-login-ip-throttle.txt';
        for ($i = 0; $i < $remaining; $i++) {
            $get = http_request('GET', $env->baseUrl . '/admin/login.php', ['cookie_jar' => $jar]);
            $csrf = extract_csrf($get['body']);
            $r = http_request('POST', $env->baseUrl . '/admin/login.php', [
                'cookie_jar' => $jar,
                'body' => ['csrf_token' => $csrf, 'email' => "ip-throttle-{$i}@example.test", 'password' => 'wrong-password'],
            ]);
            assert_true(strpos($r['body'], 'Too many requests from this connection') === false, "attempt {$i} should not be IP-throttled yet");
        }
        $get = http_request('GET', $env->baseUrl . '/admin/login.php', ['cookie_jar' => $jar]);
        $csrf = extract_csrf($get['body']);
        $blocked = http_request('POST', $env->baseUrl . '/admin/login.php', [
            'cookie_jar' => $jar,
            'body' => ['csrf_token' => $csrf, 'email' => 'ip-throttle-final@example.test', 'password' => 'wrong-password'],
        ]);
        assert_contains('Too many requests from this connection', $blocked['body']);

        // Clean up so later tests (which also log in from 127.0.0.1) aren't left throttled.
        $env->pdo()->prepare('DELETE FROM rate_limit_hits WHERE bucket = ?')->execute([$bucket]);
    });

    run_test('a nonexistent login email gets the exact same error as a wrong password for a real account', function () use ($env) {
        // Regression test for the user-enumeration fix: both must produce byte-for-byte
        // the same error text, so a nonexistent email can't be told apart from a real one
        // by its response (previously "deactivated" vs "invalid" distinguished the two).
        $jar1 = $env->tmpDir . '/cookies-enum-real.txt';
        $get1 = http_request('GET', $env->baseUrl . '/admin/login.php', ['cookie_jar' => $jar1]);
        $csrf1 = extract_csrf($get1['body']);
        $real = http_request('POST', $env->baseUrl . '/admin/login.php', [
            'cookie_jar' => $jar1,
            'body' => ['csrf_token' => $csrf1, 'email' => 'admin@example.test', 'password' => 'wrong-password-entirely'],
        ]);

        $jar2 = $env->tmpDir . '/cookies-enum-fake.txt';
        $get2 = http_request('GET', $env->baseUrl . '/admin/login.php', ['cookie_jar' => $jar2]);
        $csrf2 = extract_csrf($get2['body']);
        $fake = http_request('POST', $env->baseUrl . '/admin/login.php', [
            'cookie_jar' => $jar2,
            'body' => ['csrf_token' => $csrf2, 'email' => 'definitely-not-a-real-account@example.test', 'password' => 'wrong-password-entirely'],
        ]);

        $extractError = function (string $body) {
            preg_match('#<div class="alert">(.*?)</div>#s', $body, $m);
            return $m[1] ?? null;
        };
        assert_equal($extractError($real['body']), $extractError($fake['body']), 'the error text must be identical whether or not the account exists');

        // Clean up every failed attempt recorded against the real admin account so far
        // (this test's own, plus the earlier "rejects a wrong password" test's) — "login
        // succeeds with correct credentials..." below counts login_attempts rows for it
        // from a clean slate.
        $env->pdo()->prepare("DELETE FROM login_attempts WHERE identifier LIKE 'admin@example.test|%'")->execute();
    });

    run_test('begin_serialized_window_transaction() actually serializes concurrent writers, not just two autocommit statements', function () use ($env) {
        if ($env->isMysql) skip_test('SQLite-specific (BEGIN IMMEDIATE); see 94_real_concurrency.php for the MySQL equivalent');
        // The point of wrapping the lockout/rate-limit count-then-insert in a transaction
        // is that a second writer physically cannot interleave with it — proving that
        // needs two genuinely separate DB connections (the HTTP test server is a single
        // php -S process handling one request at a time anyway, so driving this through
        // HTTP requests would prove nothing about the locking itself). This opens two raw
        // PDO connections to the same test database and shows that a second connection
        // cannot begin its own serialized window transaction while the first still holds
        // one open — only once it commits/rolls back can the second proceed. MySQL's
        // locking-read (FOR UPDATE / gap lock) equivalent for production isn't exercised
        // here since this suite only runs against SQLite, but the same function is used
        // for both; this is a regression test for the locking primitive itself, not for
        // a specific caller.
        require_once $env->root . '/public_html/includes/auth.php';

        $connA = new PDO('sqlite:' . $env->dbPath);
        $connB = new PDO('sqlite:' . $env->dbPath);
        $connB->exec('PRAGMA busy_timeout = 200');

        begin_serialized_window_transaction($connA);
        $blocked = false;
        try {
            begin_serialized_window_transaction($connB);
            $connB->exec('ROLLBACK');
        } catch (Throwable $e) {
            $blocked = true;
        }
        assert_true($blocked, 'a second connection must not be able to start its own write transaction while the first still holds one open');

        $connA->exec('ROLLBACK');

        // Once A releases the lock, B must be able to proceed normally — this isn't
        // permanently wedged, just serialized.
        begin_serialized_window_transaction($connB);
        $connB->exec('ROLLBACK');
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

        // record_login_attempt() is now reserved as a failure *before* the password check
        // runs (so a burst of concurrent attempts can't all pass is_locked_out() before any
        // of them are recorded — see auth.php), then flipped to succeeded on a correct
        // login. The user-enumeration test above cleared every prior login_attempts row
        // for this identifier, so this successful login must add exactly one row, flipped
        // to succeeded, not a leftover succeeded=0 placeholder plus a separate row.
        $stmt = $env->pdo()->prepare('SELECT succeeded FROM login_attempts WHERE identifier = ? ORDER BY id');
        $stmt->execute(['admin@example.test|127.0.0.1']);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        assert_equal(1, count($rows), 'expected exactly this successful attempt, no leftover rows');
        assert_equal(1, (int) $rows[0], 'the reserved attempt for this login should have been flipped to succeeded');
    });
};
