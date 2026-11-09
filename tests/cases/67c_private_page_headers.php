<?php
// Pages behind a sign-in must not be kept in the browser's cache, or the Back button shows them after signing out
// (owners' names and addresses, applicants' documents). require_login() / require_vet_login() send no-store
// explicitly - PHP's own session default happens to do it too, but that is a server setting we cannot see - and the
// logout pages destroy the session, expire its cookie and send Clear-Site-Data: "cache".
return function (TestEnv $env): void {
    // Every sign-in counts toward the per-IP login cap the rest of the suite shares: give back what is used here.
    $hitsBefore = (int) $env->scalar('SELECT COALESCE(MAX(id), 0) FROM rate_limit_hits');
    $giveBack = function () use ($env, $hitsBefore): void {
        $env->pdo()->prepare("DELETE FROM rate_limit_hits WHERE bucket LIKE 'login_ip:%' AND id > ?")->execute([$hitsBefore]);
    };
    $headerLines = fn (string $raw): array => array_values(array_filter(array_map('trim', preg_split('/\r\n/', $raw))));
    $has = fn (string $raw, string $line): bool => in_array($line, $headerLines($raw), true);
    $hasPrefix = function (string $raw, string $prefix) use ($headerLines): bool {
        foreach ($headerLines($raw) as $l) if (stripos($l, $prefix) === 0) return true;
        return false;
    };
    $cookieInJar = function (string $jar, string $name): ?string {
        $text = is_file($jar) ? (string) file_get_contents($jar) : '';
        return preg_match('/\t' . preg_quote($name, '/') . '\t(\S+)\s*$/m', $text, $m) ? $m[1] : null;
    };
    $newStaff = function (string $email, string $role = 'pharmacy_staff') use ($env): void {
        $env->pdo()->prepare('INSERT INTO staff_users (name, email, password_hash, role) VALUES (?, ?, ?, ?)')
            ->execute(['Header Test', $email, password_hash('private-pages-password-1', PASSWORD_DEFAULT), $role]);
    };
    $staffSignIn = function (string $jar, string $email) use ($env): void {
        $get = http_request('GET', $env->baseUrl . '/admin/login.php', ['cookie_jar' => $jar]);
        $r = http_request('POST', $env->baseUrl . '/admin/login.php', ['cookie_jar' => $jar, 'body' => ['csrf_token' => extract_csrf($get['body']), 'email' => $email, 'password' => 'private-pages-password-1']]);
        assert_equal(302, $r['status'], 'signed in');
    };
    // An approved vet with a working portal account (the portal pages need one).
    $newVet = function (string $email) use ($env): void {
        $uniq = bin2hex(random_bytes(3));
        $env->pdo()->prepare(
            "INSERT INTO vet_applications (full_name, professional_email, mobile, registration_number, registration_state, qualification, year_qualified, practice_type, clinic_name, clinic_address, clinic_city, clinic_state, clinic_pin, clinic_phone, status)
             VALUES ('Dr Header Test', ?, '9000088888', ?, 'MH', 'BVSc', 2020, 'x', 'Header Clinic', 'x', 'x', 'x', '000000', '9000088888', 'approved')"
        )->execute([$email, "MH-HDR-{$uniq}"]);
        $appId = (int) $env->scalar('SELECT id FROM vet_applications WHERE registration_number = ?', ["MH-HDR-{$uniq}"]);
        $env->pdo()->prepare("INSERT INTO vet_accounts (vet_application_id, email, password_hash, status) VALUES (?, ?, ?, 'active')")
            ->execute([$appId, $email, password_hash('private-pages-password-1', PASSWORD_DEFAULT)]);
    };
    $vetSignIn = function (string $jar, string $email) use ($env): void {
        $get = http_request('GET', $env->baseUrl . '/for-veterinarians/login.php', ['cookie_jar' => $jar]);
        $r = http_request('POST', $env->baseUrl . '/for-veterinarians/login.php', ['cookie_jar' => $jar, 'body' => ['csrf_token' => extract_csrf($get['body']), 'email' => $email, 'password' => 'private-pages-password-1']]);
        assert_equal(302, $r['status'], 'vet signed in');
    };

    run_test('every admin page behind the sign-in sends no-store explicitly (our own values, not just PHP\'s session default)', function () use ($env, $has, $headerLines) {
        $caseId = $env->shared['gsRequestId'];
        $appId  = (int) $env->scalar('SELECT id FROM vet_applications ORDER BY id LIMIT 1');
        $pages = [
            '/admin/gs-requests/', "/admin/gs-requests/view.php?id={$caseId}", '/admin/veterinary-applications/', "/admin/veterinary-applications/view.php?id={$appId}",
            '/admin/staff/', '/admin/staff/new.php', '/admin/audit-log/', '/admin/data-erasure/', '/admin/dispatches/', '/admin/dispatches/edit.php',
        ];
        foreach ($pages as $path) {
            $r = http_request('GET', $env->baseUrl . $path, ['cookie_jar' => $env->cookieJarStaff]);
            assert_equal(200, $r['status'], "{$path} opens for an admin");
            assert_true($has($r['headers'], 'Cache-Control: no-store, private'), "{$path}: Cache-Control: no-store, private");
            assert_true($has($r['headers'], 'Pragma: no-cache'), "{$path}: Pragma: no-cache");
            assert_true($has($r['headers'], 'Expires: 0'), "{$path}: Expires: 0");
            assert_true(!in_array('Cache-Control: no-store, no-cache, must-revalidate', $headerLines($r['headers']), true), "{$path}: ours replaced PHP's session default (so this test tells them apart)");
        }
        // The page that bounces a signed-out visitor to the login also sends it (nothing is cached there either).
        $out = http_request('GET', $env->baseUrl . '/admin/gs-requests/');
        assert_equal(302, $out['status']);
        assert_true($has($out['headers'], 'Cache-Control: no-store, private'));
    });

    run_test('the vet portal pages (which show owners\' details too) send it as well', function () use ($env, $has, $newVet, $vetSignIn, $giveBack) {
        $email = 'hdr.vet.' . bin2hex(random_bytes(3)) . '@example.test';
        $jar = $env->tmpDir . '/cookies-hdr-vet-' . bin2hex(random_bytes(3)) . '.txt';
        $newVet($email);
        // The case page is the portal page with the owner's and patient's names, so it must be in this list: give the vet a case.
        $acctId = (int) $env->scalar('SELECT id FROM vet_accounts WHERE email = ?', [$email]);
        $env->pdo()->prepare(
            "INSERT INTO gs_requests (source, owner_full_name, owner_email, owner_phone, owner_address, owner_city, owner_state, owner_pin, patient_name, requested_formulation, status, vet_name, vet_clinic, vet_email, vet_account_id)
             VALUES ('veterinarian', 'Header Owner', 'header.owner@example.test', '9999999999', '1 Road', 'Pune', 'MH', '411001', 'HeaderCat', 'oral', 'submitted', 'Dr Header Test', 'Header Clinic', ?, ?)"
        )->execute([$email, $acctId]);
        $caseId = (int) $env->scalar('SELECT id FROM gs_requests WHERE vet_account_id = ?', [$acctId]);
        try {
            $vetSignIn($jar, $email);
            foreach (['/for-veterinarians/portal/', '/for-veterinarians/portal/new-request.php', "/for-veterinarians/portal/view.php?id={$caseId}"] as $path) {
                $r = http_request('GET', $env->baseUrl . $path, ['cookie_jar' => $jar]);
                assert_equal(200, $r['status'], "{$path} opens for a signed-in vet");
                assert_true($has($r['headers'], 'Cache-Control: no-store, private'), "{$path}: Cache-Control: no-store, private");
                assert_true($has($r['headers'], 'Pragma: no-cache') && $has($r['headers'], 'Expires: 0'), "{$path}: Pragma and Expires");
            }
            // The page that refuses (a case that is not this vet's) is a signed-in page too: nothing cached there either.
            $r = http_request('GET', $env->baseUrl . '/for-veterinarians/portal/view.php?id=999999', ['cookie_jar' => $jar]);
            assert_equal(404, $r['status']);
            assert_true($has($r['headers'], 'Cache-Control: no-store, private'), 'the "case not found" answer is no-store as well');
        } finally {
            $env->pdo()->prepare('DELETE FROM gs_requests WHERE id = ?')->execute([$caseId]);
            $env->pdo()->prepare('DELETE FROM vet_accounts WHERE email = ?')->execute([$email]);
            $env->pdo()->prepare('DELETE FROM vet_applications WHERE professional_email = ?')->execute([$email]);
            $giveBack();
        }
    });

    run_test('staff logout destroys the session, expires the cookie and asks the browser to drop its cached pages; the old session id is dead', function () use ($env, $has, $hasPrefix, $newStaff, $staffSignIn, $cookieInJar, $giveBack) {
        $email = 'hdr.staff.' . bin2hex(random_bytes(3)) . '@example.test';
        $jar = $env->tmpDir . '/cookies-hdr-staff-' . bin2hex(random_bytes(3)) . '.txt';
        $newStaff($email);
        try {
            $staffSignIn($jar, $email);
            $page = http_request('GET', $env->baseUrl . '/admin/gs-requests/', ['cookie_jar' => $jar]);
            assert_equal(200, $page['status']);
            $oldId = $cookieInJar($jar, 'kuronyx_staff_sid');
            assert_true($oldId !== null && $oldId !== '', 'a session cookie was issued');

            $out = http_request('POST', $env->baseUrl . '/admin/logout.php', ['cookie_jar' => $jar, 'body' => ['csrf_token' => extract_csrf($page['body'])]]);
            assert_equal(302, $out['status']);
            assert_contains('/admin/login.php', (string) $out['location']);
            assert_true($has($out['headers'], 'Clear-Site-Data: "cache"'), 'the browser is told to drop its cached pages');
            assert_true($hasPrefix($out['headers'], 'Set-Cookie: kuronyx_staff_sid=deleted'), 'the session cookie is expired, not left behind');
            assert_true(stripos($out['headers'], 'Max-Age=0') !== false || stripos($out['headers'], '1970') !== false, 'with a date in the past');

            // The browser no longer has a session cookie ...
            assert_true($cookieInJar($jar, 'kuronyx_staff_sid') === null, 'the cookie is gone from the browser');
            assert_equal(302, http_request('GET', $env->baseUrl . '/admin/gs-requests/', ['cookie_jar' => $jar])['status'], 'so the next admin page is the sign-in redirect');
            // ... and even replaying the OLD cookie does nothing: the session itself was destroyed.
            $replay = http_request('GET', $env->baseUrl . '/admin/gs-requests/', ['headers' => ["Cookie: kuronyx_staff_sid={$oldId}"]]);
            assert_equal(302, $replay['status'], 'the old session id no longer works');
            assert_contains('/admin/login.php', (string) $replay['location']);
        } finally {
            $env->pdo()->prepare('DELETE FROM staff_users WHERE email = ?')->execute([$email]);
            $giveBack();
        }
    });

    run_test('vet logout does the same (it used to only unset the vet from the session, leaving the session and cookie behind)', function () use ($env, $has, $hasPrefix, $newVet, $vetSignIn, $cookieInJar, $giveBack) {
        $email = 'hdr.vet2.' . bin2hex(random_bytes(3)) . '@example.test';
        $jar = $env->tmpDir . '/cookies-hdr-vet2-' . bin2hex(random_bytes(3)) . '.txt';
        $newVet($email);
        try {
            $vetSignIn($jar, $email);
            $page = http_request('GET', $env->baseUrl . '/for-veterinarians/portal/', ['cookie_jar' => $jar]);
            assert_equal(200, $page['status']);
            $oldId = $cookieInJar($jar, 'kuronyx_vet_sid');
            assert_true($oldId !== null && $oldId !== '');

            $out = http_request('POST', $env->baseUrl . '/for-veterinarians/portal/logout.php', ['cookie_jar' => $jar, 'body' => ['csrf_token' => extract_csrf($page['body'])]]);
            assert_equal(302, $out['status']);
            assert_contains('/for-veterinarians/login.php', (string) $out['location']);
            assert_true($has($out['headers'], 'Clear-Site-Data: "cache"'));
            assert_true($hasPrefix($out['headers'], 'Set-Cookie: kuronyx_vet_sid=deleted'), 'the vet session cookie is expired');
            assert_true($cookieInJar($jar, 'kuronyx_vet_sid') === null);
            assert_equal(302, http_request('GET', $env->baseUrl . '/for-veterinarians/portal/', ['cookie_jar' => $jar])['status']);
            $replay = http_request('GET', $env->baseUrl . '/for-veterinarians/portal/', ['headers' => ["Cookie: kuronyx_vet_sid={$oldId}"]]);
            assert_equal(302, $replay['status'], 'the old vet session id no longer works');
        } finally {
            $env->pdo()->prepare('DELETE FROM vet_accounts WHERE email = ?')->execute([$email]);
            $env->pdo()->prepare('DELETE FROM vet_applications WHERE professional_email = ?')->execute([$email]);
            $giveBack();
        }
    });

    run_test('logout is still POST + CSRF only: a GET or a wrong token neither signs anyone out nor sends Clear-Site-Data', function () use ($env, $has, $hasPrefix, $newStaff, $staffSignIn, $giveBack) {
        $email = 'hdr.csrf.' . bin2hex(random_bytes(3)) . '@example.test';
        $jar = $env->tmpDir . '/cookies-hdr-csrf-' . bin2hex(random_bytes(3)) . '.txt';
        $newStaff($email);
        try {
            $staffSignIn($jar, $email);
            $get = http_request('GET', $env->baseUrl . '/admin/logout.php', ['cookie_jar' => $jar]);
            assert_true(!$has($get['headers'], 'Clear-Site-Data: "cache"') && !$hasPrefix($get['headers'], 'Set-Cookie: kuronyx_staff_sid=deleted'), 'a GET does nothing');
            $bad = http_request('POST', $env->baseUrl . '/admin/logout.php', ['cookie_jar' => $jar, 'body' => ['csrf_token' => 'not-the-token']]);
            assert_true(!$has($bad['headers'], 'Clear-Site-Data: "cache"') && !$hasPrefix($bad['headers'], 'Set-Cookie: kuronyx_staff_sid=deleted'), 'a wrong token does nothing');
            assert_equal(200, http_request('GET', $env->baseUrl . '/admin/gs-requests/', ['cookie_jar' => $jar])['status'], 'still signed in');
        } finally {
            $env->pdo()->prepare('DELETE FROM staff_users WHERE email = ?')->execute([$email]);
            $giveBack();
        }
    });
    run_test('vet portal: a failed log out says so and offers a fresh button instead of a silent redirect, a page visit signs nobody out, and the button really logs out (a Karandeep review comment)', function () use ($env, $has, $hasPrefix, $newVet, $vetSignIn, $cookieInJar, $giveBack) {
        $email = 'hdr.vet3.' . bin2hex(random_bytes(3)) . '@example.test';
        $jar = $env->tmpDir . '/cookies-hdr-vet3-' . bin2hex(random_bytes(3)) . '.txt';
        $url = $env->baseUrl . '/for-veterinarians/portal/logout.php';
        $stillIn = fn (): bool => http_request('GET', $env->baseUrl . '/for-veterinarians/portal/', ['cookie_jar' => $jar])['status'] === 200;
        $newVet($email);
        try {
            $vetSignIn($jar, $email);
            $oldId = $cookieInJar($jar, 'kuronyx_vet_sid');

            // Someone opens the address (a link, a stale bookmark): a page with a button, nobody is signed out.
            $get = http_request('GET', $url, ['cookie_jar' => $jar]);
            assert_equal(200, $get['status'], 'a page, not a silent redirect');
            assert_contains('You are still logged in', $get['body']);
            assert_true((bool) preg_match('#<form method="POST" action="/for-veterinarians/portal/logout.php">\s*<input[^>]*name="csrf_token"[^>]*>\s*<button type="submit" class="btn-primary">Log out</button>#', $get['body']), 'a form with a token and a Log out button');
            assert_true($has($get['headers'], 'Cache-Control: no-store, private'), 'not cached, like every signed-in page');
            assert_true(!$has($get['headers'], 'Clear-Site-Data: "cache"') && !$hasPrefix($get['headers'], 'Set-Cookie: kuronyx_vet_sid=deleted'), 'a page visit does not sign anyone out');
            assert_true($stillIn(), 'still logged in after the visit');

            // A sign-out with a wrong token (a stale tab): it says so, and the person is still logged in.
            $bad = http_request('POST', $url, ['cookie_jar' => $jar, 'body' => ['csrf_token' => 'not-the-token']]);
            assert_equal(200, $bad['status']);
            assert_contains("We couldn't log you out", htmlspecialchars_decode($bad['body'], ENT_QUOTES));
            assert_contains('your session is still open', $bad['body']);
            assert_true(!$has($bad['headers'], 'Clear-Site-Data: "cache"') && !$hasPrefix($bad['headers'], 'Set-Cookie: kuronyx_vet_sid=deleted'), 'a wrong token does nothing');
            assert_true($stillIn(), 'still logged in after the failed attempt');

            // The fresh button on that page works.
            $out = http_request('POST', $url, ['cookie_jar' => $jar, 'body' => ['csrf_token' => extract_csrf($bad['body'])]]);
            assert_equal(302, $out['status']);
            assert_contains('/for-veterinarians/login.php', (string) $out['location']);
            assert_true($has($out['headers'], 'Clear-Site-Data: "cache"'));
            assert_true($hasPrefix($out['headers'], 'Set-Cookie: kuronyx_vet_sid=deleted'), 'the session cookie is expired');
            assert_equal(302, http_request('GET', $env->baseUrl . '/for-veterinarians/portal/', ['headers' => ["Cookie: kuronyx_vet_sid={$oldId}"]])['status'], 'the old session id is dead');

            // Nobody signed in: nothing to log out of, so it goes to the log in page (no confusing "couldn't log you out").
            $anon = http_request('GET', $url);
            assert_equal(302, $anon['status']);
            assert_contains('/for-veterinarians/login.php', (string) $anon['location']);
            assert_true(!$hasPrefix($anon['headers'], 'Set-Cookie: kuronyx_vet_sid='), 'and no session is started for a visitor');
        } finally {
            $env->pdo()->prepare('DELETE FROM vet_accounts WHERE email = ?')->execute([$email]);
            $env->pdo()->prepare('DELETE FROM vet_applications WHERE professional_email = ?')->execute([$email]);
            $giveBack();
        }
    });
    run_test('a session id the site never issued is not adopted: a browser holding an old or planted cookie is given a fresh id, on all three areas (session.use_strict_mode)', function () use ($env) {
        // After logout the browser may still hold the old cookie, and anyone can plant a cookie value. Without strict mode PHP
        // starts a new session UNDER THAT SAME ID; with it, PHP discards the unknown id and issues its own (a Karandeep review comment).
        $made = bin2hex(random_bytes(16)); // right shape, never issued (random each run: a fixed id gets a session file the day strict mode is off)
        foreach ([
            'kuronyx_sid'       => '/for-cat-owners/',
            'kuronyx_staff_sid' => '/admin/login.php',
            'kuronyx_vet_sid'   => '/for-veterinarians/login.php',
        ] as $cookie => $page) {
            $r = http_request('GET', $env->baseUrl . $page, ['headers' => ["Cookie: {$cookie}={$made}"]]);
            assert_equal(200, $r['status'], "{$page} opens");
            assert_true((bool) preg_match('/^Set-Cookie: ' . preg_quote($cookie, '/') . '=([^;]+)/mi', $r['headers'], $m), "{$page}: the site answers with a session cookie");
            assert_true($m[1] !== $made, "{$page}: the id the browser sent was adopted ({$cookie}) - strict mode is off");
        }
    });
};
