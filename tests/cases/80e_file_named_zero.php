<?php
// The forms asked "was a file chosen?" with empty($_FILES[x]['name']). PHP's empty() is true for the string "0", so a file
// literally called "0" was reported as "A prescription upload is required" (required fields) or silently dropped
// (optional ones - the case or application was saved without the document). They now ask PHP's upload error code instead
// (upload_was_chosen() in includes/upload.php).
return function (TestEnv $env): void {
    $png = __DIR__ . '/../fixtures/tiny.png';
    $giveBack = fn () => $env->pdo()->exec("DELETE FROM rate_limit_hits WHERE bucket LIKE 'cat_owner_submit:%' OR bucket LIKE 'vet_apply_submit:%'");
    $catFields = ['owner_full_name' => 'Zero Owner', 'owner_email' => 'zero.owner@example.test', 'owner_phone' => '9876543210', 'owner_address' => '1 Road',
        'owner_city' => 'Pune', 'owner_state' => 'Maharashtra', 'owner_pin' => '411001', 'patient_name' => 'Tom', 'vet_name' => 'Dr V',
        'vet_clinic' => 'Clinic', 'vet_email' => 'v@example.test', 'vet_phone' => '02012345678', 'requested_formulation' => 'oral'];
    $postCat = function (array $fields, string $jarName) use ($env): array {
        $jar = $env->tmpDir . '/' . $jarName;
        $get = http_request('GET', $env->baseUrl . '/for-cat-owners/index.php', ['cookie_jar' => $jar]);
        return http_request('POST', $env->baseUrl . '/for-cat-owners/index.php', ['cookie_jar' => $jar,
            'body' => array_merge(['csrf_token' => extract_csrf($get['body']), 'ott' => extract_ott($get['body'])], $fields)]);
    };

    run_test('a prescription named "0" is a prescription, not a missing one', function () use ($catFields, $postCat, $png, $giveBack) {
        $giveBack();
        // consent left out on purpose: the request is refused for that, so no case is created, but the file question is answered
        $r = $postCat($catFields + ['prescription' => new CURLFile($png, 'image/png', '0')], 'cookies-zero-1.txt');
        assert_field_error($r['body'], 'consent', 'Consent is required to submit this request', 'cat');
        assert_no_field_error($r['body'], 'prescription', 'a file named 0');
        // and a really missing file is still reported
        $r = $postCat($catFields, 'cookies-zero-2.txt');
        assert_field_error($r['body'], 'prescription', 'A prescription upload is required', 'cat');
        $giveBack();
    });

    run_test('cat owners: the prescription and an optional document, both named "0", are saved with the case', function () use ($env, $catFields, $postCat, $png, $giveBack) {
        $giveBack();
        $r = $postCat($catFields + ['consent' => '1', 'prescription' => new CURLFile($png, 'image/png', '0'), 'supporting_1' => new CURLFile($png, 'image/png', '0')], 'cookies-zero-3.txt');
        assert_equal(302, $r['status'], 'the request must go through');
        $caseId = (int) $env->scalar("SELECT id FROM gs_requests WHERE owner_email = 'zero.owner@example.test' ORDER BY id DESC LIMIT 1");
        assert_true($caseId > 0, 'the case must exist');
        assert_equal(2, (int) $env->scalar('SELECT COUNT(*) FROM gs_request_documents WHERE gs_request_id = ? AND original_filename = ?', [$caseId, '0']), 'both documents named 0 must be stored (the optional one used to be dropped silently)');
        $giveBack();
    });

    run_test('vet application: a certificate and an optional ID, both named "0", are saved', function () use ($env, $png, $giveBack) {
        $giveBack();
        $jar = $env->tmpDir . '/cookies-zero-4.txt';
        $get = http_request('GET', $env->baseUrl . '/for-veterinarians/apply/index.php', ['cookie_jar' => $jar]);
        $r = http_request('POST', $env->baseUrl . '/for-veterinarians/apply/index.php', ['cookie_jar' => $jar, 'body' => [
            'csrf_token' => extract_csrf($get['body']), 'ott' => extract_ott($get['body']),
            'full_name' => 'Dr. Zero Name', 'professional_email' => 'zero.vet@example.test', 'mobile' => '9000000002',
            'registration_number' => 'MH-VET-0000', 'registration_state' => 'Maharashtra', 'registration_country' => 'India',
            'qualification' => 'BVSc', 'year_qualified' => '2016', 'practice_type' => 'Independent practice',
            'clinic_name' => 'Zero Clinic', 'clinic_address' => '3 Road', 'clinic_city' => 'Pune', 'clinic_state' => 'Maharashtra',
            'clinic_pin' => '411004', 'clinic_country' => 'India', 'clinic_phone' => '02011111112', 'consent' => '1',
            'registration_certificate' => new CURLFile($png, 'image/png', '0'), 'professional_id' => new CURLFile($png, 'image/png', '0')]]);
        assert_equal(302, $r['status'], 'a certificate named 0 must be accepted');
        $appId = (int) $env->scalar("SELECT id FROM vet_applications WHERE professional_email = 'zero.vet@example.test'");
        assert_true($appId > 0);
        assert_equal(2, (int) $env->scalar('SELECT COUNT(*) FROM vet_application_documents WHERE application_id = ? AND original_filename = ?', [$appId, '0']));
        $giveBack();
    });

    run_test('a crafted array-valued file field is refused as invalid, not mistaken for "no file" and not a crash', function () use ($catFields, $postCat, $png, $giveBack) {
        $giveBack();
        $r = $postCat($catFields + ['consent' => '1', 'prescription[]' => new CURLFile($png, 'image/png', 'rx.png')], 'cookies-zero-5.txt');
        assert_equal(200, $r['status']);
        assert_field_error($r['body'], 'prescription', 'Invalid upload.', 'cat');
        $giveBack();
    });

    run_test('no page asks "was a file chosen?" with empty() on the file name any more', function () use ($env) {
        foreach (['for-cat-owners/index.php', 'for-veterinarians/apply/index.php', 'for-veterinarians/portal/new-request.php'] as $rel) {
            $src = file_get_contents($env->root . '/public_html/' . $rel);
            assert_true(!preg_match('/empty\(\$_FILES\[[^\]]+\]\[\'name\'\]\)/', $src), "{$rel} uses empty() on a file name (a file called 0 counts as missing)");
            assert_contains('upload_was_chosen(', $src, "{$rel} must use upload_was_chosen()");
        }
    });
};
