<?php
// Regression tests for the second round of review fixes (validation, logout, honeypot,
// placeholders, dispatch locking, pagination, etc.). Concurrency-specific fixes are tested
// in the files for the feature they belong to.
return function (TestEnv $env): void {
    $pngPath = __DIR__ . '/../fixtures/tiny.png';

    run_test('array-valued request parameters are rejected up front instead of throwing a TypeError', function () use ($env) {
        $r = http_request('GET', $env->baseUrl . '/admin/login.php?x[]=1');
        assert_equal(400, $r['status']);
        $r2 = http_request('GET', $env->baseUrl . '/admin/gs-requests/?q[]=a', ['cookie_jar' => $env->cookieJarStaff]);
        assert_equal(400, $r2['status']);
    });

    run_test('logout needs a POST with a valid CSRF token — a plain GET (an <img> tag, a link prefetch) no longer signs anyone out', function () use ($env) {
        $jar = $env->tmpDir . '/cookies-logout-test.txt';
        $get = http_request('GET', $env->baseUrl . '/admin/login.php', ['cookie_jar' => $jar]);
        $csrf = extract_csrf($get['body']);
        http_request('POST', $env->baseUrl . '/admin/login.php', [
            'cookie_jar' => $jar,
            'body' => ['csrf_token' => $csrf, 'email' => 'admin@example.test', 'password' => 'correct horse battery staple'],
        ]);
        assert_equal(200, http_request('GET', $env->baseUrl . '/admin/gs-requests/', ['cookie_jar' => $jar])['status'], 'sanity: signed in');

        http_request('GET', $env->baseUrl . '/admin/logout.php', ['cookie_jar' => $jar]);
        $page = http_request('GET', $env->baseUrl . '/admin/gs-requests/', ['cookie_jar' => $jar]);
        assert_equal(200, $page['status'], 'a GET to logout.php must not end the session');

        $csrf2 = extract_csrf($page['body']);
        $out = http_request('POST', $env->baseUrl . '/admin/logout.php', ['cookie_jar' => $jar, 'body' => ['csrf_token' => $csrf2]]);
        assert_equal(302, $out['status']);
        assert_equal(302, http_request('GET', $env->baseUrl . '/admin/gs-requests/', ['cookie_jar' => $jar])['status'], 'the POST must end the session');
    });

    run_test('an over-long field on a public form is rejected as a field error, not passed through to the INSERT', function () use ($env, $pngPath) {
        $jar = $env->tmpDir . '/cookies-overlong-owner.txt';
        $get = http_request('GET', $env->baseUrl . '/for-cat-owners/index.php', ['cookie_jar' => $jar]);
        $post = http_request('POST', $env->baseUrl . '/for-cat-owners/index.php', [
            'cookie_jar' => $jar,
            'body' => [
                'csrf_token' => extract_csrf($get['body']), 'ott' => extract_ott($get['body']),
                'owner_full_name' => str_repeat('a', 151), 'owner_email' => 'overlong-owner@example.test', 'owner_phone' => '9998887700',
                'owner_address' => '1 Test St', 'owner_city' => 'Pune', 'owner_state' => 'MH', 'owner_pin' => '411001',
                'patient_name' => 'Longname', 'vet_name' => 'Dr. Rao', 'vet_clinic' => 'Rao Clinic',
                'vet_email' => 'rao@example.test', 'vet_phone' => '9123456780', 'requested_formulation' => 'oral', 'consent' => '1',
                'prescription' => new CURLFile($pngPath, 'image/png', 'prescription.png'),
            ],
        ]);
        assert_equal(200, $post['status']);
        assert_contains('has-error', $post['body']);
        assert_equal(false, $env->pdo()->query("SELECT id FROM gs_requests WHERE owner_email = 'overlong-owner@example.test'")->fetch());
    });

    run_test('the honeypot field silently swallows a bot submission without creating anything', function () use ($env, $pngPath) {
        $jar = $env->tmpDir . '/cookies-honeypot.txt';
        $get = http_request('GET', $env->baseUrl . '/for-cat-owners/index.php', ['cookie_jar' => $jar]);
        $post = http_request('POST', $env->baseUrl . '/for-cat-owners/index.php', [
            'cookie_jar' => $jar,
            'body' => [
                'csrf_token' => extract_csrf($get['body']), 'ott' => extract_ott($get['body']), 'website_url' => 'http://spam.example',
                'owner_full_name' => 'Bot Owner', 'owner_email' => 'honeypot@example.test', 'owner_phone' => '9998887701',
                'owner_address' => '1 Test St', 'owner_city' => 'Pune', 'owner_state' => 'MH', 'owner_pin' => '411001',
                'patient_name' => 'Bot', 'vet_name' => 'Dr. Rao', 'vet_clinic' => 'Rao Clinic',
                'vet_email' => 'rao@example.test', 'vet_phone' => '9123456780', 'requested_formulation' => 'oral', 'consent' => '1',
                'prescription' => new CURLFile($pngPath, 'image/png', 'prescription.png'),
            ],
        ]);
        assert_equal(302, $post['status'], 'looks like success to the bot');
        assert_equal(false, $env->pdo()->query("SELECT id FROM gs_requests WHERE owner_email = 'honeypot@example.test'")->fetch(), 'but nothing may have been stored');
    });

    run_test('final_price / dispatch_date / assign_staff are validated instead of silently coercing or throwing', function () use ($env) {
        $id = $env->shared['gsRequestId'];
        $jar = $env->cookieJarStaff;
        $post = function (array $extra) use ($env, $id, $jar) {
            $view = http_request('GET', $env->baseUrl . "/admin/gs-requests/view.php?id={$id}", ['cookie_jar' => $jar]);
            $base = ['csrf_token' => extract_csrf($view['body']), 'expected_lock_version' => $env->scalar('SELECT lock_version FROM gs_requests WHERE id = ?', [$id])];
            return http_request('POST', $env->baseUrl . "/admin/gs-requests/view.php?id={$id}", ['cookie_jar' => $jar, 'body' => array_merge($base, $extra)]);
        };
        $blank = ['action' => 'update_final', 'final_formulation' => '', 'final_concentration' => '', 'final_quantity' => '', 'final_price' => '', 'courier' => '', 'tracking_number' => '', 'dispatch_date' => '', 'closure_reason' => ''];

        assert_contains('Price must be', $post(array_merge($blank, ['final_price' => 'abc']))['body']);
        assert_contains('Price must be', $post(array_merge($blank, ['final_price' => '-5']))['body']);
        assert_contains('Price must be', $post(array_merge($blank, ['final_price' => '1e999']))['body']);
        assert_contains('valid date', $post(array_merge($blank, ['dispatch_date' => '2026-02-30']))['body']);
        assert_contains('too long', $post(array_merge($blank, ['courier' => str_repeat('c', 101)]))['body']);
        assert_contains('active staff member', $post(['action' => 'assign_staff', 'assigned_staff_id' => 999999])['body']);
    });

    run_test('an email still containing an unfilled template placeholder is refused', function () use ($env) {
        $id = $env->shared['gsRequestId'];
        $jar = $env->cookieJarStaff;
        $view = http_request('GET', $env->baseUrl . "/admin/gs-requests/view.php?id={$id}", ['cookie_jar' => $jar]);
        $countBefore = (int) $env->scalar('SELECT COUNT(*) FROM case_emails WHERE gs_request_id = ?', [$id]);
        $post = http_request('POST', $env->baseUrl . "/admin/gs-requests/view.php?id={$id}", [
            'cookie_jar' => $jar,
            'body' => [
                'csrf_token' => extract_csrf($view['body']), 'ott' => extract_ott($view['body']), 'action' => 'send_email',
                'sender' => 'hello@kuronyx.in', 'recipient' => 'priya@example.test',
                'subject' => 'Your order', 'body' => "Price: [confirm final price]\nPayment link: [insert payment link]",
            ],
        ]);
        assert_contains('unfilled placeholder', $post['body']);
        assert_equal($countBefore, (int) $env->scalar('SELECT COUNT(*) FROM case_emails WHERE gs_request_id = ?', [$id]), 'nothing may have been logged or sent');
    });

    run_test('the dispatch editor keeps published_at across unpublish/republish and rejects a stale save', function () use ($env) {
        $jar = $env->cookieJarStaff;
        $save = function (int $id, array $fields) use ($env, $jar) {
            $url = '/admin/dispatches/edit.php' . ($id ? "?id={$id}" : '');
            $get = http_request('GET', $env->baseUrl . $url, ['cookie_jar' => $jar]);
            return http_request('POST', $env->baseUrl . $url, ['cookie_jar' => $jar, 'body' => array_merge(
                ['csrf_token' => extract_csrf($get['body']), 'action' => 'save', 'slug' => '', 'excerpt' => '', 'expected_lock_version' => $id ? $env->scalar('SELECT lock_version FROM dispatches WHERE id = ?', [$id]) : 0],
                $fields
            )]);
        };
        $save(0, ['title' => 'Lock Test Article', 'body' => 'First body.', 'status' => 'published']);
        $id = (int) $env->scalar("SELECT id FROM dispatches WHERE title = 'Lock Test Article'");
        $firstPublished = $env->scalar('SELECT published_at FROM dispatches WHERE id = ?', [$id]);
        assert_true($firstPublished !== null);

        $save($id, ['title' => 'Lock Test Article', 'body' => 'First body.', 'status' => 'draft']);
        sleep(1); // published_at has one-second resolution; a reset would be visible after this
        $save($id, ['title' => 'Lock Test Article', 'body' => 'First body.', 'status' => 'published']);
        assert_equal($firstPublished, $env->scalar('SELECT published_at FROM dispatches WHERE id = ?', [$id]), 'republishing must not reset published_at');

        $get = http_request('GET', $env->baseUrl . "/admin/dispatches/edit.php?id={$id}", ['cookie_jar' => $jar]);
        $stale = http_request('POST', $env->baseUrl . "/admin/dispatches/edit.php?id={$id}", ['cookie_jar' => $jar, 'body' => [
            'csrf_token' => extract_csrf($get['body']), 'action' => 'save', 'title' => 'Lock Test Article', 'slug' => '', 'excerpt' => '',
            'body' => 'Overwriting body that must not land.', 'status' => 'published', 'expected_lock_version' => 0,
        ]]);
        assert_contains('changed by someone else', $stale['body']);
        assert_equal('First body.', $env->scalar('SELECT body FROM dispatches WHERE id = ?', [$id]), 'the stale save must not have overwritten the body');
    });

    run_test('the unsubscribe link now only confirms on GET — it takes a POST to actually unsubscribe', function () use ($env) {
        $r = http_request('GET', $env->baseUrl . '/php/unsubscribe.php?email=someone@example.test', ['cookie_jar' => $env->tmpDir . '/cookies-unsub.txt']);
        assert_equal(200, $r['status']);
        assert_contains('Confirm unsubscribe', $r['body']);
        assert_true(strpos($r['body'], "You've been unsubscribed") === false, 'GET alone must not unsubscribe anyone');
    });

    run_test('list pages paginate instead of hard-stopping at 200 rows', function () use ($env) {
        $r = http_request('GET', $env->baseUrl . '/admin/gs-requests/?page=2', ['cookie_jar' => $env->cookieJarStaff]);
        assert_equal(200, $r['status']);
        $r2 = http_request('GET', $env->baseUrl . '/admin/veterinary-applications/?page=2', ['cookie_jar' => $env->cookieJarStaff]);
        assert_equal(200, $r2['status']);
    });

    run_test('a password longer than bcrypt\'s 72 bytes is rejected rather than silently truncated', function () use ($env) {
        $jar = $env->cookieJarStaff;
        $get = http_request('GET', $env->baseUrl . '/admin/staff/new.php', ['cookie_jar' => $jar]);
        $long = str_repeat('p', 80);
        $post = http_request('POST', $env->baseUrl . '/admin/staff/new.php', ['cookie_jar' => $jar, 'body' => [
            'csrf_token' => extract_csrf($get['body']), 'name' => 'Long Pass', 'email' => 'longpass@example.test',
            'role' => 'pharmacy_staff', 'password' => $long, 'password_confirm' => $long,
        ]]);
        assert_equal(200, $post['status']);
        assert_contains('has-error', $post['body']);
        assert_equal(false, $env->pdo()->query("SELECT id FROM staff_users WHERE email = 'longpass@example.test'")->fetch());
    });

    run_test('a send that fails validation keeps the drafted email instead of wiping it', function () use ($env) {
        $id  = $env->shared['gsRequestId'];
        $jar = $env->cookieJarStaff;
        $view = http_request('GET', $env->baseUrl . "/admin/gs-requests/view.php?id={$id}", ['cookie_jar' => $jar]);
        $r = http_request('POST', $env->baseUrl . "/admin/gs-requests/view.php?id={$id}", [
            'cookie_jar' => $jar,
            'body' => [
                'csrf_token' => extract_csrf($view['body']), 'ott' => extract_ott($view['body']), 'action' => 'send_email',
                'sender' => 'ops@kuronyx.in', 'recipient' => 'priya@example.test',
                'subject' => 'My careful draft', 'body' => 'Long hand-written text. Pay here: [insert payment link]',
            ],
        ]);
        assert_contains('unfilled placeholder', $r['body']);
        assert_contains('My careful draft', $r['body'], 'the subject the staff member typed must survive the failed send');
        assert_contains('Long hand-written text.', $r['body'], 'the body the staff member typed must survive the failed send');
    });

    run_test('an over-long case note or status note is a clear error, not a 500', function () use ($env) {
        $id  = $env->shared['gsRequestId'];
        $jar = $env->cookieJarStaff;
        $view = http_request('GET', $env->baseUrl . "/admin/gs-requests/view.php?id={$id}", ['cookie_jar' => $jar]);
        $csrf = extract_csrf($view['body']);
        $r = http_request('POST', $env->baseUrl . "/admin/gs-requests/view.php?id={$id}", [
            'cookie_jar' => $jar, 'body' => ['csrf_token' => $csrf, 'action' => 'add_note', 'content' => str_repeat('a', 10001)],
        ]);
        assert_equal(200, $r['status']);
        assert_contains('note is too long', $r['body']);
        $r = http_request('POST', $env->baseUrl . "/admin/gs-requests/view.php?id={$id}", [
            'cookie_jar' => $jar,
            'body' => ['csrf_token' => $csrf, 'action' => 'change_status', 'new_status' => 'under_review', 'status_note' => str_repeat('a', 10001), 'expected_lock_version' => 0],
        ]);
        assert_equal(200, $r['status']);
        assert_contains('status note is too long', $r['body']);
    });

    run_test('resubmitting "No Email Needed" (double-click) is audited only once', function () use ($env) {
        $id  = $env->shared['gsRequestId'];
        $jar = $env->cookieJarStaff;
        $count = fn() => (int) $env->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'email_not_needed' AND entity_type = 'gs_request' AND entity_id = ?", [$id]);
        $view = http_request('GET', $env->baseUrl . "/admin/gs-requests/view.php?id={$id}", ['cookie_jar' => $jar]);
        $body = ['csrf_token' => extract_csrf($view['body']), 'ott' => extract_ott($view['body']), 'action' => 'no_email_needed'];
        $before = $count();
        $first = http_request('POST', $env->baseUrl . "/admin/gs-requests/view.php?id={$id}", ['cookie_jar' => $jar, 'body' => $body]);
        assert_contains('Noted', $first['body']);
        $second = http_request('POST', $env->baseUrl . "/admin/gs-requests/view.php?id={$id}", ['cookie_jar' => $jar, 'body' => $body]);
        assert_contains('already submitted', $second['body']);
        assert_equal($before + 1, $count());
    });
};
