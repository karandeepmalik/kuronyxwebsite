<?php
// A signed-in staff member's role and name are re-read from the database on every request (staff_identity_now() in
// auth.php, used by require_login() and download.php), not trusted from the session saved at sign-in. Before, a role
// changed in the database (a demotion by hand today, a role-change screen one day) did not apply until that person
// signed in again - and the session only expires after 4 hours of INACTIVITY, so someone who kept working never lost it.
return function (TestEnv $env): void {
    $email = 'role.refresh@example.test';
    $jar   = ''; // set to a fresh cookie jar at the start of each test (a still-signed-in jar would skip the login form)
    $newJar = fn (): string => $env->tmpDir . '/cookies-role-refresh-' . bin2hex(random_bytes(3)) . '.txt';
    // The whole suite shares one IP and every sign-in counts toward the per-IP login cap (LOGIN_IP_MAX_ATTEMPTS, 30 per
    // window), which later tests rely on being almost untouched: give back the allowance these tests use.
    $hitsBefore = (int) $env->scalar('SELECT COALESCE(MAX(id), 0) FROM rate_limit_hits');
    $giveBack = function () use ($env, $hitsBefore): void {
        $env->pdo()->prepare("DELETE FROM rate_limit_hits WHERE bucket LIKE 'login_ip:%' AND id > ?")->execute([$hitsBefore]);
    };
    $setDb = function (string $sql, array $params = []) use ($env): void {
        $env->pdo()->prepare($sql)->execute($params);
    };
    $status = function (string $path) use ($env, &$jar) { return http_request('GET', $env->baseUrl . $path, ['cookie_jar' => $jar]); };

    run_test('a role changed in the database applies on the very next request, without signing in again', function () use ($env, $email, &$jar, $newJar, $setDb, $status, $giveBack) {
        $jar = $newJar();
        $env->pdo()->prepare("INSERT INTO staff_users (name, email, password_hash, role) VALUES ('Role Refresh', ?, ?, 'admin')")
            ->execute([$email, password_hash('role-refresh-password-1', PASSWORD_DEFAULT)]);
        try {
            $get = http_request('GET', $env->baseUrl . '/admin/login.php', ['cookie_jar' => $jar]);
            $login = http_request('POST', $env->baseUrl . '/admin/login.php', ['cookie_jar' => $jar, 'body' => ['csrf_token' => extract_csrf($get['body']), 'email' => $email, 'password' => 'role-refresh-password-1']]);
            assert_equal(302, $login['status'], 'signed in as an admin');
            assert_equal(200, $status('/admin/staff/')['status'], 'an admin can open the staff page');
            assert_contains('href="/admin/audit-log/"', $status('/admin/gs-requests/')['body'], 'and the admin menu shows the admin-only pages');

            // Demoted in the database. The same session, the very next request: no longer an admin.
            $setDb("UPDATE staff_users SET role = 'pharmacy_staff' WHERE email = ?", [$email]);
            assert_equal(403, $status('/admin/staff/')['status'], 'the admin-only page is refused straight away');
            assert_equal(403, $status('/admin/audit-log/')['status']);
            $home = $status('/admin/gs-requests/');
            assert_equal(200, $home['status'], 'the pages pharmacy staff may use still work');
            assert_true(strpos($home['body'], 'href="/admin/audit-log/"') === false, 'the menu no longer offers admin-only pages');
            assert_contains('(pharmacy_staff)', $home['body'], 'and the header shows the current role');

            // Promoted again: also immediate.
            $setDb("UPDATE staff_users SET role = 'admin' WHERE email = ?", [$email]);
            assert_equal(200, $status('/admin/staff/')['status'], 'a promotion applies straight away too');
            assert_contains('(admin)', $status('/admin/gs-requests/')['body']);
        } finally {
            $giveBack();
            $setDb('DELETE FROM staff_users WHERE email = ?', [$email]);
        }
    });

    run_test('a renamed account shows the new name straight away, including on the notes it writes', function () use ($env, $email, &$jar, $newJar, $setDb, $status, $giveBack) {
        $jar = $newJar();
        $env->pdo()->prepare("INSERT INTO staff_users (name, email, password_hash, role) VALUES ('Old Name', ?, ?, 'pharmacy_staff')")
            ->execute([$email, password_hash('role-refresh-password-1', PASSWORD_DEFAULT)]);
        $uniq = bin2hex(random_bytes(3));
        $env->pdo()->prepare(
            "INSERT INTO vet_applications (full_name, professional_email, mobile, registration_number, registration_state, qualification, year_qualified, practice_type, clinic_name, clinic_address, clinic_city, clinic_state, clinic_pin, clinic_phone)
             VALUES ('Dr Name Test', ?, '9000077777', ?, 'MH', 'BVSc', 2020, 'x', 'Name Clinic', 'x', 'x', 'x', '000000', '9000077777')"
        )->execute(["name.test.{$uniq}@example.test", "MH-NAME-{$uniq}"]);
        $appId = (int) $env->scalar('SELECT id FROM vet_applications WHERE registration_number = ?', ["MH-NAME-{$uniq}"]);
        try {
            $get = http_request('GET', $env->baseUrl . '/admin/login.php', ['cookie_jar' => $jar]);
            http_request('POST', $env->baseUrl . '/admin/login.php', ['cookie_jar' => $jar, 'body' => ['csrf_token' => extract_csrf($get['body']), 'email' => $email, 'password' => 'role-refresh-password-1']]);
            assert_contains('Admin · Old Name', $status('/admin/veterinary-applications/')['body']);

            $setDb("UPDATE staff_users SET name = 'New Name' WHERE email = ?", [$email]);
            assert_contains('Admin · New Name', $status('/admin/veterinary-applications/')['body'], 'the header shows the new name at once');

            // The review note is stamped with whoever is acting, by their current name.
            $view = $status("/admin/veterinary-applications/view.php?id={$appId}");
            http_request('POST', $env->baseUrl . "/admin/veterinary-applications/view.php?id={$appId}", ['cookie_jar' => $jar, 'follow' => true, 'body' => [
                'csrf_token' => extract_csrf($view['body']), 'action' => 'add_note', 'note' => 'Called the clinic.',
            ]]);
            $notes = (string) $env->scalar('SELECT internal_notes FROM vet_applications WHERE id = ?', [$appId]);
            assert_contains('· New Name] Note: Called the clinic.', $notes, 'the note carries the current name');
            assert_true(strpos($notes, 'Old Name') === false, 'not the name from when the session began');
        } finally {
            $giveBack();
            $setDb('DELETE FROM vet_applications WHERE id = ?', [$appId]);
            $setDb('DELETE FROM staff_users WHERE email = ?', [$email]);
        }
    });

    run_test('deactivating still ends the session at once, and a password change still ends it (the checks that were already there)', function () use ($env, $email, &$jar, $newJar, $setDb, $status, $giveBack) {
        $jar = $newJar();
        $env->pdo()->prepare("INSERT INTO staff_users (name, email, password_hash, role) VALUES ('Still Booted', ?, ?, 'pharmacy_staff')")
            ->execute([$email, password_hash('role-refresh-password-1', PASSWORD_DEFAULT)]);
        try {
            $get = http_request('GET', $env->baseUrl . '/admin/login.php', ['cookie_jar' => $jar]);
            http_request('POST', $env->baseUrl . '/admin/login.php', ['cookie_jar' => $jar, 'body' => ['csrf_token' => extract_csrf($get['body']), 'email' => $email, 'password' => 'role-refresh-password-1']]);
            assert_equal(200, $status('/admin/gs-requests/')['status']);

            $setDb("UPDATE staff_users SET password_hash = ? WHERE email = ?", [password_hash('a-different-password-2', PASSWORD_DEFAULT), $email]);
            $afterReset = $status('/admin/gs-requests/');
            assert_equal(302, $afterReset['status'], 'a password change signs the old session out');
            assert_contains('/admin/login.php', (string) $afterReset['location']);

            // Back in, then deactivated.
            $get = http_request('GET', $env->baseUrl . '/admin/login.php', ['cookie_jar' => $jar]);
            http_request('POST', $env->baseUrl . '/admin/login.php', ['cookie_jar' => $jar, 'body' => ['csrf_token' => extract_csrf($get['body']), 'email' => $email, 'password' => 'a-different-password-2']]);
            assert_equal(200, $status('/admin/gs-requests/')['status']);
            $setDb('UPDATE staff_users SET active = 0 WHERE email = ?', [$email]);
            assert_equal(302, $status('/admin/gs-requests/')['status'], 'a deactivated account is signed out at once');
        } finally {
            $giveBack();
            $setDb('DELETE FROM staff_users WHERE email = ?', [$email]);
        }
    });

    run_test('download.php uses the same re-check: a deactivated staff session stops working there too', function () use ($env, $email, &$jar, $newJar, $setDb, $giveBack) {
        $jar = $newJar();
        $env->pdo()->prepare("INSERT INTO staff_users (name, email, password_hash, role) VALUES ('Doc Staff', ?, ?, 'pharmacy_staff')")
            ->execute([$email, password_hash('role-refresh-password-1', PASSWORD_DEFAULT)]);
        try {
            $get = http_request('GET', $env->baseUrl . '/admin/login.php', ['cookie_jar' => $jar]);
            http_request('POST', $env->baseUrl . '/admin/login.php', ['cookie_jar' => $jar, 'body' => ['csrf_token' => extract_csrf($get['body']), 'email' => $email, 'password' => 'role-refresh-password-1']]);
            $before = http_request('GET', $env->baseUrl . '/download.php?kind=gs_request&doc_id=99999999', ['cookie_jar' => $jar]);
            assert_equal(404, $before['status'], 'signed in: an unknown document is a 404, not a sign-in redirect');
            $setDb('UPDATE staff_users SET active = 0 WHERE email = ?', [$email]);
            $after = http_request('GET', $env->baseUrl . '/download.php?kind=gs_request&doc_id=99999999', ['cookie_jar' => $jar]);
            assert_true($after['status'] !== 404, 'deactivated: no longer treated as signed in');
        } finally {
            $giveBack();
            $setDb('DELETE FROM staff_users WHERE email = ?', [$email]);
        }
    });
};
