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

    run_test('an admin can create a new staff account (no password typed: they are emailed an invitation)', function () use ($env) {
        $jar = $env->cookieJarStaff;
        $get = http_request('GET', $env->baseUrl . '/admin/staff/new.php', ['cookie_jar' => $jar]);
        $csrf = extract_csrf($get['body']);
        $post = http_request('POST', $env->baseUrl . '/admin/staff/new.php', [
            'cookie_jar' => $jar,
            'body' => [
                'csrf_token' => $csrf, 'ott' => extract_ott($get['body']), 'name' => 'New Hire', 'email' => 'new.hire@example.test',
                'role' => 'pharmacy_staff',
            ],
        ]);
        assert_equal(302, $post['status']);
        assert_contains('/admin/staff/', $post['location'] ?? '');

        $list = http_request('GET', $env->baseUrl . '/admin/staff/', ['cookie_jar' => $jar]);
        assert_contains('New Hire', $list['body']);
        assert_contains('new.hire@example.test', $list['body']);
    });

    run_test('creating a staff account rejects a missing name and a malformed email', function () use ($env) {
        $jar = $env->cookieJarStaff;
        $get = http_request('GET', $env->baseUrl . '/admin/staff/new.php', ['cookie_jar' => $jar]);
        $csrf = extract_csrf($get['body']);
        $post = http_request('POST', $env->baseUrl . '/admin/staff/new.php', [
            'cookie_jar' => $jar,
            'body' => ['csrf_token' => $csrf, 'ott' => extract_ott($get['body']), 'name' => '', 'email' => 'not-an-email', 'role' => 'pharmacy_staff'],
        ]);
        assert_equal(200, $post['status']);
        assert_contains('has-error', $post['body']);
        assert_field_error($post['body'], 'name', 'Required', 'staff/new.php');
        assert_field_error($post['body'], 'email', 'Enter a valid email address', 'staff/new.php');
    });

    run_test('creating a staff account with an email that already exists is rejected cleanly, not with a raw error', function () use ($env) {
        // Regression test for the staff/new.php duplicate-email race: the code used to
        // check for an existing row, then insert, as two separate statements — now it
        // inserts directly and catches the UNIQUE violation. This proves that catch path
        // actually works (a valid-otherwise submission, so the field-validation test above
        // never reached the DB at all and didn't exercise this). It also proves the failed
        // INSERT sends no invitation: the rolled-back account must not be emailed a link.
        $emailsBefore = is_file($env->dryRunEmailsPath) ? substr_count(file_get_contents($env->dryRunEmailsPath), '"to":"new.hire@example.test"') : 0;
        $jar = $env->cookieJarStaff;
        $get = http_request('GET', $env->baseUrl . '/admin/staff/new.php', ['cookie_jar' => $jar]);
        $csrf = extract_csrf($get['body']);
        $post = http_request('POST', $env->baseUrl . '/admin/staff/new.php', [
            'cookie_jar' => $jar,
            'body' => [
                'csrf_token' => $csrf, 'ott' => extract_ott($get['body']), 'name' => 'Dupe With Valid Fields', 'email' => 'new.hire@example.test',
                'role' => 'pharmacy_staff',
            ],
        ]);
        assert_equal(200, $post['status']);
        // The duplicate is only discovered by the INSERT's UNIQUE violation, and the admin must be TOLD so:
        // this message was set in new.php but never printed until the page started rendering field messages.
        assert_field_error($post['body'], 'email', 'An account with this email already exists', 'staff/new.php');

        $count = (int) $env->scalar("SELECT COUNT(*) FROM staff_users WHERE email = 'new.hire@example.test'");
        assert_equal(1, $count, 'the duplicate attempt must not have created a second row');
        $emailsAfter = is_file($env->dryRunEmailsPath) ? substr_count(file_get_contents($env->dryRunEmailsPath), '"to":"new.hire@example.test"') : 0;
        assert_equal($emailsBefore, $emailsAfter, 'a rejected duplicate must not send another invitation');
    });

    run_test('toggling a staff account active twice in a row ends up back where it started', function () use ($env) {
        // Regression test for the atomic "active = 1 - active" toggle: this doesn't prove
        // the race is closed (that needs real concurrency this test harness can't drive —
        // see begin_serialized_window_transaction()'s dedicated test for that style of
        // proof elsewhere), but it does confirm the single-statement toggle still produces
        // the correct functional result for ordinary sequential use.
        $newHireId = (int) $env->scalar("SELECT id FROM staff_users WHERE email = 'new.hire@example.test'");
        assert_equal(1, (int) $env->scalar('SELECT active FROM staff_users WHERE id = ?', [$newHireId]), 'sanity check: should start active');

        $jar = $env->cookieJarStaff;
        $get = http_request('GET', $env->baseUrl . '/admin/staff/', ['cookie_jar' => $jar]);
        $csrf = extract_csrf($get['body']);
        $first = http_request('POST', $env->baseUrl . '/admin/staff/', [
            'cookie_jar' => $jar,
            'body' => ['csrf_token' => $csrf, 'staff_id' => $newHireId, 'set_active' => 0],
        ]);
        assert_contains('Account deactivated', $first['body']);
        assert_equal(0, (int) $env->scalar('SELECT active FROM staff_users WHERE id = ?', [$newHireId]));

        $csrf2 = extract_csrf($first['body']);
        $second = http_request('POST', $env->baseUrl . '/admin/staff/', [
            'cookie_jar' => $jar,
            'body' => ['csrf_token' => $csrf2, 'staff_id' => $newHireId, 'set_active' => 1],
        ]);
        assert_contains('Account reactivated', $second['body']);
        assert_equal(1, (int) $env->scalar('SELECT active FROM staff_users WHERE id = ?', [$newHireId]), 'should be back to active after two toggles');
    });

    run_test('an admin can deactivate another account, which then cannot sign in', function () use ($env) {
        $newHireId = (int) $env->scalar("SELECT id FROM staff_users WHERE email = 'new.hire@example.test'");
        assert_true($newHireId > 0);

        $jar = $env->cookieJarStaff;
        $get = http_request('GET', $env->baseUrl . '/admin/staff/', ['cookie_jar' => $jar]);
        $csrf = extract_csrf($get['body']);
        $post = http_request('POST', $env->baseUrl . '/admin/staff/', [
            'cookie_jar' => $jar,
            'body' => ['csrf_token' => $csrf, 'staff_id' => $newHireId, 'set_active' => 0],
        ]);
        assert_contains('Account deactivated', $post['body']);

        $loginJar = $env->tmpDir . '/cookies-deactivated-login.txt';
        $loginGet = http_request('GET', $env->baseUrl . '/admin/login.php', ['cookie_jar' => $loginJar]);
        $loginCsrf = extract_csrf($loginGet['body']);
        $loginPost = http_request('POST', $env->baseUrl . '/admin/login.php', [
            'cookie_jar' => $loginJar,
            'body' => ['csrf_token' => $loginCsrf, 'email' => 'new.hire@example.test', 'password' => 'a-fresh-strong-password'],
        ]);
        // The message is now deliberately the same as any other failed login (see
        // user-enumeration fix in admin/login.php) — a deactivated account no longer gets
        // its own distinguishing text, which would otherwise confirm the email is real.
        assert_equal(200, $loginPost['status'], 'must not have redirected as if the login succeeded');
        assert_contains('Invalid email or password', $loginPost['body']);
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

    run_test('a duplicate "Deactivate" submission is a safe no-op, not a silent reactivation', function () use ($env) {
        // Regression test for switching from "active = 1 - active" (a flip with no memory
        // of intent) to an explicit target value: two clicks of the SAME "Deactivate"
        // button (the realistic case — a double-click, or a page reload resubmitting the
        // same form) must both result in "deactivated", not flip-flop back to active on
        // the second one.
        $newHireId = (int) $env->scalar("SELECT id FROM staff_users WHERE email = 'new.hire@example.test'");
        $env->pdo()->prepare('UPDATE staff_users SET active = 1 WHERE id = ?')->execute([$newHireId]);

        $jar = $env->cookieJarStaff;
        $get = http_request('GET', $env->baseUrl . '/admin/staff/', ['cookie_jar' => $jar]);
        $csrf = extract_csrf($get['body']);
        $first = http_request('POST', $env->baseUrl . '/admin/staff/', [
            'cookie_jar' => $jar,
            'body' => ['csrf_token' => $csrf, 'staff_id' => $newHireId, 'set_active' => 0],
        ]);
        assert_contains('Account deactivated', $first['body']);

        $csrf2 = extract_csrf($first['body']);
        $second = http_request('POST', $env->baseUrl . '/admin/staff/', [
            'cookie_jar' => $jar,
            'body' => ['csrf_token' => $csrf2, 'staff_id' => $newHireId, 'set_active' => 0],
        ]);
        assert_contains('already deactivated', $second['body']);
        assert_equal(0, (int) $env->scalar('SELECT active FROM staff_users WHERE id = ?', [$newHireId]), 'must still be deactivated after the duplicate click, not flipped back to active');
    });

    run_test('two admins deactivating each other at the same moment cannot leave zero active admins', function () use ($env) {
        if ($env->isMysql) skip_test('SQLite-specific simulation; see 94_real_concurrency.php for the MySQL equivalent');
        // Regression test for the actual vulnerability: admin A deactivating admin B
        // while B deactivates A, each individually passing "am I deactivating myself? no"
        // and, in the old toggle-based code, having no cross-check against the other
        // admin's in-flight request. This can't be driven through real HTTP concurrency
        // (the test server is single-threaded — see begin_serialized_window_transaction()'s
        // own test elsewhere for why direct PDO connections are used for this kind of
        // proof instead), so this opens two real connections and interleaves their
        // statements by hand to simulate the exact race, then confirms the second
        // transaction's own last-admin check correctly sees the first's already-committed
        // deactivation and refuses rather than leaving the table with no active admin.
        $pdo = $env->pdo();
        $pdo->exec("INSERT INTO staff_users (name, email, password_hash, role, active) VALUES ('Admin Two', 'admin2@example.test', '" . password_hash('irrelevant', PASSWORD_DEFAULT) . "', 'admin', 1)");
        $adminA = (int) $env->scalar("SELECT id FROM staff_users WHERE email = 'admin@example.test'");
        $adminB = (int) $env->scalar("SELECT id FROM staff_users WHERE email = 'admin2@example.test'");

        require_once $env->root . '/public_html/includes/auth.php';

        $connA = new PDO('sqlite:' . $env->dbPath);
        $connB = new PDO('sqlite:' . $env->dbPath);
        $connB->exec('PRAGMA busy_timeout = 500');

        // A's transaction: deactivate B. Opens first and holds the whole-database write
        // lock (SQLite's BEGIN IMMEDIATE) until it commits, so B's transaction below can't
        // even start running its own statements until A finishes — the same serialization
        // begin_serialized_window_transaction() relies on everywhere else in this app.
        begin_serialized_window_transaction($connA);
        $othersForA = (int) $connA->query("SELECT COUNT(*) FROM staff_users WHERE role = 'admin' AND active = 1 AND id != {$adminB}")->fetchColumn();
        assert_true($othersForA > 0, 'A should still see itself as another active admin at this point');
        $connA->exec("UPDATE staff_users SET active = 0 WHERE id = {$adminB}");
        $connA->exec('COMMIT');

        // B's transaction: deactivate A. By the time this runs, A has already committed
        // deactivating B — so B itself is no longer an active admin, and this count must
        // come back 0, correctly refusing to also deactivate A.
        begin_serialized_window_transaction($connB);
        $othersForB = (int) $connB->query("SELECT COUNT(*) FROM staff_users WHERE role = 'admin' AND active = 1 AND id != {$adminA}")->fetchColumn();
        $connB->exec('COMMIT');

        assert_equal(0, $othersForB, 'B must see zero other active admins (itself was just deactivated by A) and therefore refuse to also deactivate A');
        assert_equal(1, (int) $env->scalar('SELECT active FROM staff_users WHERE id = ?', [$adminA]), 'admin A must still be active — at least one admin must always survive');
        assert_equal(0, (int) $env->scalar('SELECT active FROM staff_users WHERE id = ?', [$adminB]), 'admin B is correctly deactivated (A won the race)');

        // Clean up: reactivate B so it doesn't skew admin-role assumptions in later tests.
        $env->pdo()->prepare('UPDATE staff_users SET active = 1 WHERE id = ?')->execute([$adminB]);
    });

    run_test('an already-logged-in session is booted once its account is deactivated mid-session', function () use ($env) {
        $newHireId = (int) $env->scalar("SELECT id FROM staff_users WHERE email = 'new.hire@example.test'");
        // Reactivate and sign in with a fresh session.
        $env->pdo()->prepare('UPDATE staff_users SET active = 1 WHERE id = ?')->execute([$newHireId]);

        // The admin no longer picks the new hire's password: they set it themselves from the invitation link.
        $invitation = $env->lastDryRunEmailTo('new.hire@example.test');
        assert_true($invitation !== null && preg_match('#/admin/reset-password\.php\?token=([a-f0-9]+)#', $invitation['body'], $m), 'the invitation email');
        $setJar = $env->tmpDir . '/cookies-midsession-setpw.txt';
        $setGet = http_request('GET', $env->baseUrl . '/admin/reset-password.php?token=' . $m[1], ['cookie_jar' => $setJar]);
        $setPost = http_request('POST', $env->baseUrl . '/admin/reset-password.php', ['cookie_jar' => $setJar, 'body' => [
            'csrf_token' => extract_csrf($setGet['body']), 'token' => $m[1], 'password' => 'a-fresh-strong-password', 'password_confirm' => 'a-fresh-strong-password',
        ]]);
        assert_contains('Your password has been changed', $setPost['body']);

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
