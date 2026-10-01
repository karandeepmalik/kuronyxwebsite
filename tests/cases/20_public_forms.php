<?php
return function (TestEnv $env): void {
    $pngPath = __DIR__ . '/../fixtures/tiny.png';

    run_test('vet application form rejects a submission missing required fields', function () use ($env) {
        $jar = $env->tmpDir . '/cookies-vetapply-bad.txt';
        $get = http_request('GET', $env->baseUrl . '/for-veterinarians/apply/index.php', ['cookie_jar' => $jar]);
        $csrf = extract_csrf($get['body']);
        $post = http_request('POST', $env->baseUrl . '/for-veterinarians/apply/index.php', [
            'cookie_jar' => $jar,
            'body' => ['csrf_token' => $csrf, 'consent' => '1'],
        ]);
        assert_equal(200, $post['status']);
        assert_contains('has-error', $post['body']);
        assert_contains('Please check the highlighted fields', $post['body']);
    });

    run_test('vet application form accepts a valid submission', function () use ($env) {
        $jar = $env->tmpDir . '/cookies-vetapply-ok.txt';
        $get = http_request('GET', $env->baseUrl . '/for-veterinarians/apply/index.php', ['cookie_jar' => $jar]);
        $csrf = extract_csrf($get['body']);
        $post = http_request('POST', $env->baseUrl . '/for-veterinarians/apply/index.php', [
            'cookie_jar' => $jar,
            'body' => [
                'csrf_token' => $csrf,
                'full_name' => 'Dr. Asha Verma',
                'professional_email' => 'asha.verma@example.test',
                'mobile' => '9876543210',
                'registration_number' => 'MH-VET-1234',
                'registration_state' => 'Maharashtra',
                'registration_country' => 'India',
                'qualification' => 'BVSc & AH',
                'year_qualified' => '2015',
                'practice_type' => 'Independent practice',
                'clinic_name' => 'Verma Pet Clinic',
                'clinic_address' => '12 MG Road',
                'clinic_city' => 'Pune',
                'clinic_state' => 'Maharashtra',
                'clinic_pin' => '411001',
                'clinic_country' => 'India',
                'clinic_phone' => '02012345678',
                'consent' => '1',
            ],
        ]);
        assert_equal(302, $post['status']);
        assert_contains('/request-received', $post['location'] ?? '');

        $row = $env->pdo()->query("SELECT * FROM vet_applications WHERE professional_email = 'asha.verma@example.test'")->fetch();
        assert_true($row !== false, 'application row should exist');
        assert_equal('pending', $row['status']);

        $received = http_request('GET', $env->baseUrl . '/request-received/index.php', ['cookie_jar' => $jar]);
        assert_contains('veterinary account application has been received', $received['body']);
    });

    run_test('cat owner request requires a prescription upload', function () use ($env) {
        $jar = $env->tmpDir . '/cookies-catowner-nofile.txt';
        $get = http_request('GET', $env->baseUrl . '/for-cat-owners/index.php', ['cookie_jar' => $jar]);
        $csrf = extract_csrf($get['body']);
        $post = http_request('POST', $env->baseUrl . '/for-cat-owners/index.php', [
            'cookie_jar' => $jar,
            'body' => [
                'csrf_token' => $csrf,
                'owner_full_name' => 'Ravi Shah', 'owner_email' => 'ravi@example.test', 'owner_phone' => '9998887776',
                'owner_address' => '1 Park Street', 'owner_city' => 'Mumbai', 'owner_state' => 'Maharashtra', 'owner_pin' => '400001',
                'patient_name' => 'Momo', 'vet_name' => 'Dr. Rao', 'vet_clinic' => 'Rao Clinic',
                'vet_email' => 'rao@example.test', 'vet_phone' => '9123456780',
                'requested_formulation' => 'oral', 'consent' => '1',
            ],
        ]);
        assert_contains('has-error', $post['body']);
        assert_contains('Please check the highlighted fields', $post['body']);
    });

    run_test('cat owner request rejects a calendar-invalid date of birth instead of silently rolling it over', function () use ($env, $pngPath) {
        // DateTime::createFromFormat('Y-m-d', ...) doesn't fail on an invalid calendar date
        // like "2026-02-30" — it silently rolls over to the next valid one (March 2) instead,
        // so a naive truthy check on the result let bad dates through and stored the wrong
        // value. This must now be rejected, not rolled over and saved.
        $jar = $env->tmpDir . '/cookies-catowner-baddob.txt';
        $get = http_request('GET', $env->baseUrl . '/for-cat-owners/index.php', ['cookie_jar' => $jar]);
        $csrf = extract_csrf($get['body']);
        $post = http_request('POST', $env->baseUrl . '/for-cat-owners/index.php', [
            'cookie_jar' => $jar,
            'body' => [
                'csrf_token' => $csrf,
                'owner_full_name' => 'Bad Date Owner', 'owner_email' => 'baddate@example.test', 'owner_phone' => '9998887772',
                'owner_address' => '3 Test Street', 'owner_city' => 'Delhi', 'owner_state' => 'Delhi', 'owner_pin' => '110001',
                'patient_name' => 'Rollover', 'patient_dob' => '2026-02-30',
                'vet_name' => 'Dr. Rao', 'vet_clinic' => 'Rao Clinic', 'vet_email' => 'rao2@example.test', 'vet_phone' => '9123456782',
                'requested_formulation' => 'oral', 'consent' => '1',
                'prescription' => new CURLFile($pngPath, 'image/png', 'prescription.png'),
            ],
        ]);
        assert_equal(200, $post['status']);
        assert_contains('has-error', $post['body']);
        assert_contains('Please check the highlighted fields', $post['body']);

        $row = $env->pdo()->query("SELECT * FROM gs_requests WHERE owner_email = 'baddate@example.test'")->fetch();
        assert_true($row === false, 'no row should have been stored for a request with an invalid date of birth');
    });

    run_test('cat owner request succeeds with a prescription upload', function () use ($env, $pngPath) {
        $jar = $env->tmpDir . '/cookies-catowner-ok.txt';
        $get = http_request('GET', $env->baseUrl . '/for-cat-owners/index.php', ['cookie_jar' => $jar]);
        $csrf = extract_csrf($get['body']);
        $post = http_request('POST', $env->baseUrl . '/for-cat-owners/index.php', [
            'cookie_jar' => $jar,
            'body' => [
                'csrf_token' => $csrf,
                'owner_full_name' => 'Priya Nair', 'owner_email' => 'priya@example.test', 'owner_phone' => '9998887771',
                'owner_address' => '2 Lake Road', 'owner_city' => 'Chennai', 'owner_state' => 'Tamil Nadu', 'owner_pin' => '600001',
                'patient_name' => 'Simba', 'vet_name' => 'Dr. Iyer', 'vet_clinic' => 'Iyer Clinic',
                'vet_email' => 'iyer@example.test', 'vet_phone' => '9123456781',
                'requested_formulation' => 'injection', 'consent' => '1',
                'prescription' => new CURLFile($pngPath, 'image/png', 'prescription.png'),
            ],
        ]);
        assert_equal(302, $post['status']);
        assert_contains('/request-received', $post['location'] ?? '');

        $row = $env->pdo()->query("SELECT * FROM gs_requests WHERE owner_email = 'priya@example.test'")->fetch();
        assert_true($row !== false, 'gs_request row should exist');
        assert_equal('cat_owner', $row['source']);
        assert_equal('submitted', $row['status']);

        $stmt = $env->pdo()->prepare('SELECT COUNT(*) FROM gs_request_documents WHERE gs_request_id = ?');
        $stmt->execute([$row['id']]);
        assert_equal(1, (int) $stmt->fetchColumn(), 'one prescription document should be recorded');

        $env->shared['gsRequestId'] = (int) $row['id'];
    });
};
