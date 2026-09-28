<?php
return function (TestEnv $env): void {
    run_test('a cat-owner prescription upload is relocated out of the pending bucket', function () use ($env) {
        $id = $env->shared['gsRequestId'];
        $stored = $env->scalar("SELECT stored_filename FROM gs_request_documents WHERE gs_request_id = ? AND doc_type = 'prescription'", [$id]);
        assert_true(is_string($stored) && $stored !== '', 'expected a stored prescription filename');

        assert_true(is_file($env->storageDir . "/gs-requests/{$id}/{$stored}"), 'file should be filed under its own case folder');
        assert_true(!is_file($env->storageDir . "/gs-requests/pending/{$stored}"), 'file should no longer sit in the pending bucket');
    });

    run_test('a vet application document upload is relocated to its own application folder', function () use ($env) {
        $pngPath = __DIR__ . '/../fixtures/tiny.png';
        $jar = $env->tmpDir . '/cookies-vetapply-doc.txt';
        $get = http_request('GET', $env->baseUrl . '/for-veterinarians/apply/index.php', ['cookie_jar' => $jar]);
        $csrf = extract_csrf($get['body']);
        $post = http_request('POST', $env->baseUrl . '/for-veterinarians/apply/index.php', [
            'cookie_jar' => $jar,
            'body' => [
                'csrf_token' => $csrf,
                'full_name' => 'Dr. Refile Test', 'professional_email' => 'refile.test@example.test', 'mobile' => '9000000001',
                'registration_number' => 'MH-VET-1000', 'registration_state' => 'Maharashtra', 'registration_country' => 'India',
                'qualification' => 'BVSc & AH', 'year_qualified' => '2019', 'practice_type' => 'Independent practice',
                'clinic_name' => 'Refile Clinic', 'clinic_address' => '1 Refile Rd', 'clinic_city' => 'Pune',
                'clinic_state' => 'Maharashtra', 'clinic_pin' => '411005', 'clinic_country' => 'India', 'clinic_phone' => '02011112222',
                'consent' => '1',
                'registration_certificate' => new CURLFile($pngPath, 'image/png', 'cert.png'),
            ],
        ]);
        assert_equal(302, $post['status']);

        $appId = (int) $env->scalar("SELECT id FROM vet_applications WHERE professional_email = 'refile.test@example.test'");
        assert_true($appId > 0);
        $stored = $env->scalar('SELECT stored_filename FROM vet_application_documents WHERE application_id = ?', [$appId]);
        assert_true(is_string($stored) && $stored !== '', 'expected a stored document filename');

        assert_true(is_file($env->storageDir . "/vet-applications/{$appId}/{$stored}"), 'file should be filed under its own application folder');
        assert_true(!is_file($env->storageDir . "/vet-applications/pending/{$stored}"), 'file should no longer sit in the pending bucket');
    });

    run_test('a vet-portal supporting document upload is relocated to its own case folder', function () use ($env) {
        // Not $env->shared['vetPortalJar'] — that vet account was suspended by the
        // last test in 80_vet_portal.php (and then reactivated under a fresh login
        // in its own throwaway jar), so sign in again here instead.
        $pngPath = __DIR__ . '/../fixtures/tiny.png';
        $jar = $env->tmpDir . '/cookies-vetportal-refile.txt';
        $vlogin = http_request('GET', $env->baseUrl . '/for-veterinarians/login.php', ['cookie_jar' => $jar]);
        $vcsrf = extract_csrf($vlogin['body']);
        http_request('POST', $env->baseUrl . '/for-veterinarians/login.php', [
            'cookie_jar' => $jar,
            'body' => ['csrf_token' => $vcsrf, 'email' => 'portal.vet@example.test', 'password' => 'reactivated-password-1'],
        ]);

        $get = http_request('GET', $env->baseUrl . '/for-veterinarians/portal/new-request.php', ['cookie_jar' => $jar]);
        $csrf = extract_csrf($get['body']);
        $post = http_request('POST', $env->baseUrl . '/for-veterinarians/portal/new-request.php', [
            'cookie_jar' => $jar,
            'body' => [
                'csrf_token' => $csrf,
                'owner_full_name' => 'Refile Owner', 'owner_email' => 'refileowner@example.test', 'owner_phone' => '9333333333',
                'owner_address' => '9 Refile Ave', 'owner_city' => 'Pune', 'owner_state' => 'Maharashtra', 'owner_pin' => '411006',
                'patient_name' => 'Refile Cat', 'requested_formulation' => 'oral', 'consent' => '1',
                'prescription' => new CURLFile($pngPath, 'image/png', 'prescription.png'),
                'supporting_1' => new CURLFile($pngPath, 'image/png', 'lab-report.png'),
            ],
        ]);
        assert_equal(302, $post['status']);

        $reqId = (int) $env->scalar("SELECT id FROM gs_requests WHERE owner_email = 'refileowner@example.test'");
        assert_true($reqId > 0);
        $stored = $env->scalar("SELECT stored_filename FROM gs_request_documents WHERE gs_request_id = ? AND doc_type = 'supporting'", [$reqId]);

        assert_true(is_file($env->storageDir . "/gs-requests/{$reqId}/{$stored}"), 'file should be filed under its own case folder');
        assert_true(!is_file($env->storageDir . "/gs-requests/pending/{$stored}"), 'file should no longer sit in the pending bucket');

        $docId = (int) $env->scalar("SELECT id FROM gs_request_documents WHERE gs_request_id = ? AND doc_type = 'supporting'", [$reqId]);
        $dl = http_request('GET', $env->baseUrl . "/download.php?kind=gs_request&doc_id={$docId}", ['cookie_jar' => $jar]);
        assert_equal(200, $dl['status'], 'the relocated file should still be downloadable');
    });
};
