<?php
// Covers the SQLite-invisible MySQL limits (DECIMAL(5,2) weight, TEXT = 65,535 bytes), the
// multi-tab one-time tokens, and the required registration certificate.
return function (TestEnv $env): void {
    $pngPath = __DIR__ . '/../fixtures/tiny.png';

    $catOwnerFields = function (array $extra = []) use ($pngPath): array {
        return $extra + [
            'owner_full_name' => 'Robust Owner', 'owner_email' => 'robust@example.test', 'owner_phone' => '9998887700',
            'owner_address' => '1 Test Street', 'owner_city' => 'Delhi', 'owner_state' => 'Delhi', 'owner_pin' => '110001',
            'patient_name' => 'Robust', 'vet_name' => 'Dr. Rao', 'vet_clinic' => 'Rao Clinic',
            'vet_email' => 'rao.robust@example.test', 'vet_phone' => '9123456700',
            'requested_formulation' => 'oral', 'consent' => '1',
            'prescription' => new CURLFile($pngPath, 'image/png', 'prescription.png'),
        ];
    };
    $submitCatOwner = function (array $extra) use ($env, $catOwnerFields): array {
        $jar = $env->tmpDir . '/cookies-robust-' . bin2hex(random_bytes(4)) . '.txt';
        $get = http_request('GET', $env->baseUrl . '/for-cat-owners/index.php', ['cookie_jar' => $jar]);
        return http_request('POST', $env->baseUrl . '/for-cat-owners/index.php', [
            'cookie_jar' => $jar,
            'body' => $catOwnerFields(['csrf_token' => extract_csrf($get['body']), 'ott' => extract_ott($get['body'])] + $extra),
        ]);
    };
    $clearBucket = function () use ($env) {
        $env->pdo()->exec("DELETE FROM rate_limit_hits WHERE bucket = 'cat_owner_submit:127.0.0.1'");
    };

    run_test('a patient weight beyond DECIMAL(5,2), or infinite, is a field error; the maximum is accepted', function () use ($env, $submitCatOwner, $clearBucket) {
        foreach (['1000', '999.995', '1e999', '-1', '0', 'abc'] as $bad) {
            $r = $submitCatOwner(['patient_weight_kg' => $bad, 'owner_email' => "w{$bad}@example.test"]);
            assert_equal(200, $r['status'], "weight '{$bad}' should be bounced");
            assert_contains('has-error', $r['body']);
        }
        assert_equal(0, (int) $env->scalar("SELECT COUNT(*) FROM gs_requests WHERE owner_full_name = 'Robust Owner'"), 'no case may be stored for a bad weight');
        $ok = $submitCatOwner(['patient_weight_kg' => '999.99', 'owner_email' => 'maxweight@example.test']);
        assert_equal(302, $ok['status'], 'the largest value the column holds is accepted');
        assert_true((float) $env->scalar("SELECT patient_weight_kg FROM gs_requests WHERE owner_email = 'maxweight@example.test'") === 999.99, 'stored weight');
        $clearBucket();
    });

    run_test('clinical notes are limited in bytes (TEXT), not just characters', function () use ($env, $submitCatOwner, $clearBucket) {
        // 17,000 characters is under the 20,000-character cap but 68,000 bytes, over a TEXT column's 65,535.
        $r = $submitCatOwner(['clinical_notes' => str_repeat("\u{1F600}", 17000), 'owner_email' => 'bytes@example.test']);
        assert_equal(200, $r['status']);
        assert_contains('has-error', $r['body']);
        assert_equal(0, (int) $env->scalar("SELECT COUNT(*) FROM gs_requests WHERE owner_email = 'bytes@example.test'"));
        $clearBucket();
    });

    run_test('the same form open in two tabs: the older tab\'s one-time token still works, once', function () use ($env, $catOwnerFields) {
        $jar = $env->tmpDir . '/cookies-robust-tabs.txt';
        $tabA = http_request('GET', $env->baseUrl . '/for-cat-owners/index.php', ['cookie_jar' => $jar]);
        $tabB = http_request('GET', $env->baseUrl . '/for-cat-owners/index.php', ['cookie_jar' => $jar]);
        $ottA = extract_ott($tabA['body']);
        $ottB = extract_ott($tabB['body']);
        assert_true($ottA !== $ottB, 'each render gets its own token');
        $csrf = extract_csrf($tabB['body']);

        // Blank body => a field-error bounce, which proves the token itself was accepted.
        $first = http_request('POST', $env->baseUrl . '/for-cat-owners/index.php', ['cookie_jar' => $jar, 'body' => ['csrf_token' => $csrf, 'ott' => $ottA]]);
        assert_true(strpos($first['body'], 'already submitted') === false, 'the older tab\'s token must still be accepted');
        assert_contains('Please check the highlighted fields', $first['body']);

        $replay = http_request('POST', $env->baseUrl . '/for-cat-owners/index.php', ['cookie_jar' => $jar, 'body' => ['csrf_token' => $csrf, 'ott' => $ottA]]);
        assert_contains('already submitted', $replay['body']);

        $second = http_request('POST', $env->baseUrl . '/for-cat-owners/index.php', ['cookie_jar' => $jar, 'body' => ['csrf_token' => $csrf, 'ott' => $ottB]]);
        assert_true(strpos($second['body'], 'already submitted') === false, 'the other tab\'s token is independent');
    });

    run_test('a vet application without a registration certificate is refused', function () use ($env) {
        $jar = $env->tmpDir . '/cookies-robust-nocert.txt';
        $get = http_request('GET', $env->baseUrl . '/for-veterinarians/apply/index.php', ['cookie_jar' => $jar]);
        $post = http_request('POST', $env->baseUrl . '/for-veterinarians/apply/index.php', [
            'cookie_jar' => $jar,
            'body' => [
                'csrf_token' => extract_csrf($get['body']), 'ott' => extract_ott($get['body']),
                'full_name' => 'Dr. No Certificate', 'professional_email' => 'nocert.vet@example.test', 'mobile' => '9876543200',
                'registration_number' => 'MH-VET-0002', 'registration_state' => 'Maharashtra', 'registration_country' => 'India',
                'qualification' => 'BVSc & AH', 'year_qualified' => '2015', 'practice_type' => 'Independent practice',
                'clinic_name' => 'No Cert Clinic', 'clinic_address' => '1 Rd', 'clinic_city' => 'Pune',
                'clinic_state' => 'Maharashtra', 'clinic_pin' => '411002', 'clinic_country' => 'India', 'clinic_phone' => '02012345601',
                'consent' => '1',
            ],
        ]);
        assert_equal(200, $post['status']);
        assert_contains('has-error', $post['body']);
        assert_equal(0, (int) $env->scalar("SELECT COUNT(*) FROM vet_applications WHERE professional_email = 'nocert.vet@example.test'"));
        $env->pdo()->exec("DELETE FROM rate_limit_hits WHERE bucket = 'vet_apply_submit:127.0.0.1'");
    });
};
