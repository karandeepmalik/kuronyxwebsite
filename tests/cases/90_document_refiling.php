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
        $ott  = extract_ott($get['body']);
        $post = http_request('POST', $env->baseUrl . '/for-veterinarians/apply/index.php', [
            'cookie_jar' => $jar,
            'body' => [
                'csrf_token' => $csrf, 'ott' => $ott,
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
        $ott  = extract_ott($get['body']);
        $post = http_request('POST', $env->baseUrl . '/for-veterinarians/portal/new-request.php', [
            'cookie_jar' => $jar,
            'body' => [
                'csrf_token' => $csrf, 'ott' => $ott,
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

    run_test('delete_uploaded_file() removes an orphaned upload and no-ops when the file is absent', function () use ($env) {
        // Submission failures used to leave store_uploaded_file()'s output on disk forever
        // when the DB transaction that was meant to reference it then rolled back — nothing
        // ever deleted it. The fix added delete_uploaded_file() and wired it into every
        // intake form's catch block. Triggering a genuine mid-transaction DB failure
        // through the public forms isn't reliably portable across MySQL/SQLite without
        // relying on engine-specific quirks, so this tests the cleanup helper itself
        // directly: it must remove a file that's actually there, and safely no-op for a
        // subfolder the file was never relocated to (the two locations every catch block
        // checks, since relocate_uploaded_file() may or may not have already run).
        require_once $env->root . '/public_html/includes/storage-path.php';
        require_once $env->root . '/public_html/includes/upload.php';

        $subfolder = 'gs-requests/pending';
        $dir = $env->storageDir . '/' . $subfolder;
        if (!is_dir($dir)) mkdir($dir, 0750, true);
        $filename = 'orphan-test-' . bin2hex(random_bytes(8)) . '.png';
        file_put_contents($dir . '/' . $filename, 'not a real image, just a cleanup test fixture');
        assert_true(is_file($dir . '/' . $filename), 'fixture file should exist before cleanup');

        delete_uploaded_file($filename, 'gs-requests/does-not-exist');
        assert_true(is_file($dir . '/' . $filename), 'deleting from a subfolder the file is not in must not touch it');

        delete_uploaded_file($filename, $subfolder);
        assert_true(!is_file($dir . '/' . $filename), 'the orphaned upload should now be removed');
    });
};
