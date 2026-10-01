<?php
return function (TestEnv $env): void {
    run_test('portal pages redirect an anonymous visitor to vet login', function () use ($env) {
        $r = http_request('GET', $env->baseUrl . '/for-veterinarians/portal/', ['cookie_jar' => $env->tmpDir . '/cookies-vetanon.txt']);
        assert_equal(302, $r['status']);
        assert_contains('/for-veterinarians/login.php', $r['location'] ?? '');
    });

    run_test('a new vet application can be approved into an activation email', function () use ($env) {
        $jar = $env->tmpDir . '/cookies-vetapply2.txt';
        $get = http_request('GET', $env->baseUrl . '/for-veterinarians/apply/index.php', ['cookie_jar' => $jar]);
        $csrf = extract_csrf($get['body']);
        $post = http_request('POST', $env->baseUrl . '/for-veterinarians/apply/index.php', [
            'cookie_jar' => $jar,
            'body' => [
                'csrf_token' => $csrf,
                'full_name' => 'Dr. Portal Vet', 'professional_email' => 'portal.vet@example.test', 'mobile' => '9000000000',
                'registration_number' => 'MH-VET-9999', 'registration_state' => 'Maharashtra', 'registration_country' => 'India',
                'qualification' => 'BVSc & AH', 'year_qualified' => '2018', 'practice_type' => 'Independent practice',
                'clinic_name' => 'Portal Vet Clinic', 'clinic_address' => '1 Clinic Rd', 'clinic_city' => 'Pune',
                'clinic_state' => 'Maharashtra', 'clinic_pin' => '411002', 'clinic_country' => 'India', 'clinic_phone' => '02099999999',
                'consent' => '1',
            ],
        ]);
        assert_equal(302, $post['status']);

        $appId = (int) $env->scalar("SELECT id FROM vet_applications WHERE professional_email = 'portal.vet@example.test'");
        assert_true($appId > 0);

        $jarStaff = $env->cookieJarStaff;
        $view = http_request('GET', $env->baseUrl . "/admin/veterinary-applications/view.php?id={$appId}", ['cookie_jar' => $jarStaff]);
        $csrf = extract_csrf($view['body']);
        $approve = http_request('POST', $env->baseUrl . "/admin/veterinary-applications/view.php?id={$appId}", [
            'cookie_jar' => $jarStaff,
            'body' => ['csrf_token' => $csrf, 'action' => 'approve', 'note' => ''],
        ]);
        assert_contains('portal account has been created', $approve['body']);
        assert_true(strpos($approve['body'], 'notified by email') === false, 'approving no longer sends anything automatically');

        $accountId = (int) $env->scalar('SELECT id FROM vet_accounts WHERE email = ?', ['portal.vet@example.test']);
        assert_true($accountId > 0, 'a vet_accounts row should have been created');
        assert_equal('pending_activation', $env->scalar('SELECT status FROM vet_accounts WHERE id = ?', [$accountId]));

        // Nothing is emailed automatically — generate a fresh activation link, then
        // send it manually through the same template/sender/recipient compose panel
        // used everywhere else.
        $csrf2 = extract_csrf($approve['body']);
        $gen = http_request('POST', $env->baseUrl . "/admin/veterinary-applications/view.php?id={$appId}", [
            'cookie_jar' => $jarStaff,
            'body' => ['csrf_token' => $csrf2, 'action' => 'generate_activation_link', 'note' => ''],
        ]);
        assert_true((bool) preg_match('#https://kuronyx\.in/for-veterinarians/activate\.php\?token=[a-f0-9]+#', $gen['body'], $m), 'activation link not found in the compose panel');
        $link = $m[0];

        $csrf3 = extract_csrf($gen['body']);
        $send = http_request('POST', $env->baseUrl . "/admin/veterinary-applications/view.php?id={$appId}", [
            'cookie_jar' => $jarStaff,
            'body' => [
                'csrf_token' => $csrf3, 'action' => 'send_email', 'sender' => 'hello@kuronyx.in',
                'recipient' => 'portal.vet@example.test', 'subject' => 'Your Kuronyx veterinary account is approved',
                'body' => "Set your password to activate:\n{$link}\n\nReference: VA-{$appId}",
            ],
        ]);
        assert_contains('Email sent', $send['body']);

        $email = $env->lastDryRunEmailTo('portal.vet@example.test');
        assert_true($email !== null, 'the activation email should have been logged');
        assert_true((bool) preg_match('#/for-veterinarians/activate\.php\?token=([a-f0-9]+)#', $email['body'], $m2), 'activation link not found in email body');
        $env->shared['vetActivationToken'] = $m2[1];
    });

    run_test('the activation link lets the vet set a password', function () use ($env) {
        $token = $env->shared['vetActivationToken'];
        $jar = $env->tmpDir . '/cookies-activate.txt';
        $get = http_request('GET', $env->baseUrl . '/for-veterinarians/activate.php?token=' . $token, ['cookie_jar' => $jar]);
        assert_equal(200, $get['status']);
        assert_contains('Set a password', $get['body']);
        $csrf = extract_csrf($get['body']);

        $post = http_request('POST', $env->baseUrl . '/for-veterinarians/activate.php', [
            'cookie_jar' => $jar,
            'body' => ['csrf_token' => $csrf, 'token' => $token, 'password' => 'vet-portal-password-1', 'password_confirm' => 'vet-portal-password-1'],
        ]);
        assert_contains('Your account is active', $post['body']);
        assert_equal('active', $env->scalar("SELECT status FROM vet_accounts WHERE email = 'portal.vet@example.test'"));
    });

    run_test('a spent or bogus activation token no longer works', function () use ($env) {
        $token = $env->shared['vetActivationToken'];
        $r = http_request('GET', $env->baseUrl . '/for-veterinarians/activate.php?token=' . $token, ['cookie_jar' => $env->tmpDir . '/cookies-activate2.txt']);
        assert_contains('invalid or has expired', $r['body']);
    });

    run_test('the vet can sign in and reaches an empty dashboard', function () use ($env) {
        $jar = $env->tmpDir . '/cookies-vetlogin.txt';
        $get = http_request('GET', $env->baseUrl . '/for-veterinarians/login.php', ['cookie_jar' => $jar]);
        $csrf = extract_csrf($get['body']);
        $post = http_request('POST', $env->baseUrl . '/for-veterinarians/login.php', [
            'cookie_jar' => $jar,
            'body' => ['csrf_token' => $csrf, 'email' => 'portal.vet@example.test', 'password' => 'vet-portal-password-1'],
        ]);
        assert_equal(302, $post['status']);
        assert_contains('/for-veterinarians/portal/', $post['location'] ?? '');

        $dash = http_request('GET', $env->baseUrl . '/for-veterinarians/portal/', ['cookie_jar' => $jar]);
        assert_contains("haven't submitted any requests", $dash['body']);
        $env->shared['vetPortalJar'] = $jar;
    });

    run_test('the vet portal also requires a prescription upload', function () use ($env) {
        $jar = $env->shared['vetPortalJar'];
        $get = http_request('GET', $env->baseUrl . '/for-veterinarians/portal/new-request.php', ['cookie_jar' => $jar]);
        $csrf = extract_csrf($get['body']);
        $post = http_request('POST', $env->baseUrl . '/for-veterinarians/portal/new-request.php', [
            'cookie_jar' => $jar,
            'body' => [
                'csrf_token' => $csrf,
                'owner_full_name' => 'Portal Client', 'owner_email' => 'client@example.test', 'owner_phone' => '9111111111',
                'owner_address' => '5 Client Rd', 'owner_city' => 'Pune', 'owner_state' => 'Maharashtra', 'owner_pin' => '411003',
                'patient_name' => 'Tom', 'requested_formulation' => 'oral', 'consent' => '1',
            ],
        ]);
        assert_equal(200, $post['status']);
        assert_contains('has-error', $post['body']);
        assert_contains('Please check the highlighted fields', $post['body']);
    });

    run_test('the vet portal also rejects a calendar-invalid date of birth instead of silently rolling it over', function () use ($env) {
        // Same bug as the cat-owner form (both share this exact validation block):
        // DateTime::createFromFormat('Y-m-d', ...) rolls an invalid calendar date like
        // "2026-02-30" over to the next valid one instead of failing, so a naive truthy
        // check let it through and stored the wrong date.
        $jar = $env->shared['vetPortalJar'];
        $pngPath = __DIR__ . '/../fixtures/tiny.png';
        $get = http_request('GET', $env->baseUrl . '/for-veterinarians/portal/new-request.php', ['cookie_jar' => $jar]);
        $csrf = extract_csrf($get['body']);
        $post = http_request('POST', $env->baseUrl . '/for-veterinarians/portal/new-request.php', [
            'cookie_jar' => $jar,
            'body' => [
                'csrf_token' => $csrf,
                'owner_full_name' => 'Bad Date Client', 'owner_email' => 'baddateclient@example.test', 'owner_phone' => '9111111112',
                'owner_address' => '6 Client Rd', 'owner_city' => 'Pune', 'owner_state' => 'Maharashtra', 'owner_pin' => '411003',
                'patient_name' => 'Rollover', 'patient_dob' => '2026-02-30', 'requested_formulation' => 'oral', 'consent' => '1',
                'prescription' => new CURLFile($pngPath, 'image/png', 'prescription.png'),
            ],
        ]);
        assert_equal(200, $post['status']);
        assert_contains('has-error', $post['body']);
        assert_contains('Please check the highlighted fields', $post['body']);

        $row = $env->pdo()->query("SELECT * FROM gs_requests WHERE owner_email = 'baddateclient@example.test'")->fetch();
        assert_true($row === false, 'no row should have been stored for a request with an invalid date of birth');
    });

    run_test('the vet can submit a GS-441524 request from the portal with a prescription attached', function () use ($env) {
        $jar = $env->shared['vetPortalJar'];
        $pngPath = __DIR__ . '/../fixtures/tiny.png';
        $get = http_request('GET', $env->baseUrl . '/for-veterinarians/portal/new-request.php', ['cookie_jar' => $jar]);
        $csrf = extract_csrf($get['body']);
        $post = http_request('POST', $env->baseUrl . '/for-veterinarians/portal/new-request.php', [
            'cookie_jar' => $jar,
            'body' => [
                'csrf_token' => $csrf,
                'owner_full_name' => 'Portal Client', 'owner_email' => 'client@example.test', 'owner_phone' => '9111111111',
                'owner_address' => '5 Client Rd', 'owner_city' => 'Pune', 'owner_state' => 'Maharashtra', 'owner_pin' => '411003',
                'patient_name' => 'Tom', 'requested_formulation' => 'oral', 'consent' => '1',
                'prescription' => new CURLFile($pngPath, 'image/png', 'prescription.png'),
            ],
        ]);
        assert_equal(302, $post['status']);
        assert_contains('/for-veterinarians/portal/view.php', $post['location'] ?? '');

        $row = $env->pdo()->query("SELECT * FROM gs_requests WHERE owner_email = 'client@example.test'")->fetch();
        assert_true($row !== false);
        assert_equal('veterinarian', $row['source']);
        assert_equal('Dr. Portal Vet', $row['vet_name']);
        $accountId = (int) $env->scalar('SELECT id FROM vet_accounts WHERE email = ?', ['portal.vet@example.test']);
        assert_equal($accountId, (int) $row['vet_account_id']);
        $env->shared['vetGsRequestId'] = (int) $row['id'];

        $docCount = (int) $env->scalar(
            "SELECT COUNT(*) FROM gs_request_documents WHERE gs_request_id = ? AND doc_type = 'prescription'",
            [$row['id']]
        );
        assert_equal(1, $docCount, 'one prescription document should be recorded');

        $view = http_request('GET', $env->baseUrl . '/for-veterinarians/portal/view.php?id=' . $row['id'] . '&submitted=1', ['cookie_jar' => $jar]);
        assert_equal(200, $view['status']);
        assert_contains('Portal Client', $view['body']);
        assert_contains('Request submitted', $view['body']);
    });

    run_test('this request also shows up correctly in the admin list', function () use ($env) {
        $r = http_request('GET', $env->baseUrl . '/admin/gs-requests/?source=veterinarian', ['cookie_jar' => $env->cookieJarStaff]);
        assert_contains('Portal Client', $r['body']);
        assert_contains('Veterinarian', $r['body']);
    });

    run_test('a vet cannot fetch a vet_application document through download.php', function () use ($env) {
        $docId = (int) $env->scalar('SELECT id FROM vet_application_documents LIMIT 1');
        if ($docId <= 0) return; // no vet_application_documents were uploaded in this run — nothing to check
        $r = http_request('GET', $env->baseUrl . "/download.php?kind=vet_application&doc_id={$docId}", ['cookie_jar' => $env->shared['vetPortalJar']]);
        assert_equal(403, $r['status']);
    });

    run_test('suspending the application locks the vet out of the portal', function () use ($env) {
        $appId = (int) $env->scalar("SELECT id FROM vet_applications WHERE professional_email = 'portal.vet@example.test'");
        $jarStaff = $env->cookieJarStaff;
        $view = http_request('GET', $env->baseUrl . "/admin/veterinary-applications/view.php?id={$appId}", ['cookie_jar' => $jarStaff]);
        $csrf = extract_csrf($view['body']);
        http_request('POST', $env->baseUrl . "/admin/veterinary-applications/view.php?id={$appId}", [
            'cookie_jar' => $jarStaff,
            'body' => ['csrf_token' => $csrf, 'action' => 'suspend', 'note' => ''],
        ]);
        assert_equal('suspended', $env->scalar("SELECT status FROM vet_accounts WHERE email = 'portal.vet@example.test'"));

        $jar = $env->tmpDir . '/cookies-vetsuspended.txt';
        $get = http_request('GET', $env->baseUrl . '/for-veterinarians/login.php', ['cookie_jar' => $jar]);
        $csrf2 = extract_csrf($get['body']);
        $post = http_request('POST', $env->baseUrl . '/for-veterinarians/login.php', [
            'cookie_jar' => $jar,
            'body' => ['csrf_token' => $csrf2, 'email' => 'portal.vet@example.test', 'password' => 'vet-portal-password-1'],
        ]);
        assert_contains('Invalid email or password', $post['body']);
    });

    run_test('a suspended vet cannot use a still-live session to keep downloading their own documents', function () use ($env) {
        // $env->shared['vetPortalJar'] logged in before the suspend above — the session
        // cookie is still technically valid, but current_vet() alone (unlike
        // require_vet_login()) never used to re-check status, so download.php would
        // otherwise keep serving files to an already-suspended vet on this cookie.
        $docId = (int) $env->scalar(
            'SELECT id FROM gs_request_documents WHERE gs_request_id = ?',
            [$env->shared['vetGsRequestId']]
        );
        assert_true($docId > 0, 'expected the prescription document from the earlier portal submission');
        $r = http_request('GET', $env->baseUrl . "/download.php?kind=gs_request&doc_id={$docId}", ['cookie_jar' => $env->shared['vetPortalJar']]);
        assert_equal(302, $r['status'], 'a suspended vet must be treated as logged out here too, not just on portal pages');
        assert_contains('/admin/login.php', $r['location'] ?? '');
    });

    run_test('re-approving a suspended-but-already-activated application reactivates it directly, keeping the existing password', function () use ($env) {
        $appId = (int) $env->scalar("SELECT id FROM vet_applications WHERE professional_email = 'portal.vet@example.test'");
        assert_true($env->scalar('SELECT password_hash FROM vet_accounts WHERE vet_application_id = ?', [$appId]) !== null, 'sanity check: this account was activated with a real password earlier in this file');

        $jarStaff = $env->cookieJarStaff;
        $view = http_request('GET', $env->baseUrl . "/admin/veterinary-applications/view.php?id={$appId}", ['cookie_jar' => $jarStaff]);
        $csrf = extract_csrf($view['body']);
        $post = http_request('POST', $env->baseUrl . "/admin/veterinary-applications/view.php?id={$appId}", [
            'cookie_jar' => $jarStaff,
            'body' => ['csrf_token' => $csrf, 'action' => 'approve', 'note' => ''],
        ]);
        assert_contains('reactivated', $post['body']);
        assert_equal('active', $env->scalar('SELECT status FROM vet_accounts WHERE vet_application_id = ?', [$appId]), 'an already-passworded account should go straight back to active, not pending_activation');

        // The vet's ORIGINAL password (from before the suspension) should still work —
        // reactivating must not touch password_hash, only status.
        $jar = $env->tmpDir . '/cookies-vetportal-reactivated.txt';
        $get = http_request('GET', $env->baseUrl . '/for-veterinarians/login.php', ['cookie_jar' => $jar]);
        $csrf2 = extract_csrf($get['body']);
        $login = http_request('POST', $env->baseUrl . '/for-veterinarians/login.php', [
            'cookie_jar' => $jar,
            'body' => ['csrf_token' => $csrf2, 'email' => 'portal.vet@example.test', 'password' => 'vet-portal-password-1'],
        ]);
        assert_equal(302, $login['status'], 'the vet should be able to sign back in with their original password, unchanged');
        assert_contains('/for-veterinarians/portal/', $login['location'] ?? '');
    });

    run_test('logging into one of staff/vet portal clears a lingering identity from the other (shared session cookie)', function () use ($env) {
        // Re-activate a fresh vet account for this check (the portal.vet one above is now
        // suspended). Uses a throwaway PDO handle that closes immediately — see the note
        // in 40_admin_vet_applications.php about not holding one open across http_request().
        (function () use ($env) {
            $env->pdo()->prepare("UPDATE vet_accounts SET status = 'active', password_hash = ? WHERE email = 'portal.vet@example.test'")
                ->execute([password_hash('reactivated-password-1', PASSWORD_DEFAULT)]);
        })();

        $jar = $env->tmpDir . '/cookies-shared-identity.txt';

        // Log in as staff first.
        $get = http_request('GET', $env->baseUrl . '/admin/login.php', ['cookie_jar' => $jar]);
        $csrf = extract_csrf($get['body']);
        http_request('POST', $env->baseUrl . '/admin/login.php', [
            'cookie_jar' => $jar,
            'body' => ['csrf_token' => $csrf, 'email' => 'admin@example.test', 'password' => 'correct horse battery staple'],
        ]);
        $adminPage = http_request('GET', $env->baseUrl . '/admin/gs-requests/', ['cookie_jar' => $jar]);
        assert_equal(200, $adminPage['status'], 'should be a valid staff session at this point');

        // Now log into the vet portal on the SAME cookie jar/session.
        $get2 = http_request('GET', $env->baseUrl . '/for-veterinarians/login.php', ['cookie_jar' => $jar]);
        $csrf2 = extract_csrf($get2['body']);
        http_request('POST', $env->baseUrl . '/for-veterinarians/login.php', [
            'cookie_jar' => $jar,
            'body' => ['csrf_token' => $csrf2, 'email' => 'portal.vet@example.test', 'password' => 'reactivated-password-1'],
        ]);

        // The staff identity should no longer work on this same cookie jar.
        $adminAfter = http_request('GET', $env->baseUrl . '/admin/gs-requests/', ['cookie_jar' => $jar]);
        assert_equal(302, $adminAfter['status'], 'staff session should have been cleared by the vet login');
        assert_contains('/admin/login.php', $adminAfter['location'] ?? '');

        // ...but the vet portal itself should still work.
        $portalPage = http_request('GET', $env->baseUrl . '/for-veterinarians/portal/', ['cookie_jar' => $jar]);
        assert_equal(200, $portalPage['status']);
    });
};
