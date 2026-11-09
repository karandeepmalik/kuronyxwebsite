<?php
// The three public intake forms (cat owners, vet application, vet-portal request) used to set a message for every bad field
// ("Enter a valid phone number", "A prescription upload is required") and print none of them: each field only got a red
// border and the person saw "Please check the highlighted fields" and had to guess. They now print each message under its
// field (includes/form-errors.php), say how long is too long, put a refused upload's reason under that file, keep the
// consent tick, and move the cursor to the first problem. assert_field_error() checks the element itself, because the
// stylesheet puts "has-error" on every page.
return function (TestEnv $env): void {
    $txt = __DIR__ . '/../fixtures/invalid.txt';
    $png = __DIR__ . '/../fixtures/tiny.png';
    $focus = 'querySelector(\'[aria-invalid="true"]\')';

    $catBase = [
        'owner_full_name' => 'Asha Owner', 'owner_email' => 'asha@example.test', 'owner_phone' => '9876543210',
        'owner_address' => '1 Road', 'owner_city' => 'Pune', 'owner_state' => 'Maharashtra', 'owner_pin' => '411001',
        'patient_name' => 'Tom', 'vet_name' => 'Dr V', 'vet_clinic' => 'Clinic', 'vet_email' => 'v@example.test', 'vet_phone' => '02012345678',
        'requested_formulation' => 'oral', 'consent' => '1',
    ];
    $postCat = function (array $fields, string $jarName) use ($env): array {
        $jar = $env->tmpDir . '/' . $jarName;
        $get = http_request('GET', $env->baseUrl . '/for-cat-owners/index.php', ['cookie_jar' => $jar]);
        return http_request('POST', $env->baseUrl . '/for-cat-owners/index.php', [
            'cookie_jar' => $jar,
            'body' => array_merge(['csrf_token' => extract_csrf($get['body']), 'ott' => extract_ott($get['body'])], $fields),
        ]);
    };
    $giveBack = function () use ($env) {
        $env->pdo()->exec("DELETE FROM rate_limit_hits WHERE bucket LIKE 'cat_owner_submit:%' OR bucket LIKE 'vet_apply_submit:%'");
    };

    run_test('cat-owner form: every bad field shows its own message, with the banner and the focus script', function () use ($postCat, $giveBack, $focus) {
        $giveBack();
        $post = $postCat(['owner_email' => 'not-an-email', 'owner_phone' => 'call me', 'patient_dob' => '2030-01-01', 'patient_weight_kg' => '5000',
            'patient_sex' => 'robot', 'requested_formulation' => 'gel'], 'cookies-pf-cat1.txt');
        assert_equal(200, $post['status']);
        assert_contains('Please check the highlighted fields', $post['body']);
        $b = $post['body'];
        assert_field_error($b, 'owner_full_name', 'Please fill this in', 'cat');
        assert_field_error($b, 'owner_address', 'Please fill this in', 'cat');
        assert_field_error($b, 'owner_email', 'Enter a valid email address', 'cat');
        assert_field_error($b, 'owner_phone', 'Enter a valid phone number', 'cat');
        assert_field_error($b, 'patient_dob', 'Enter a valid date', 'cat');
        assert_field_error($b, 'patient_weight_kg', 'Enter a valid weight (up to 999.99 kg)', 'cat');
        assert_field_error($b, 'patient_sex', 'Select a valid option', 'cat');
        assert_field_error($b, 'requested_formulation', 'Select a formulation', 'cat');
        assert_field_error($b, 'consent', 'Consent is required to submit this request', 'cat');
        assert_field_error($b, 'prescription', 'A prescription upload is required', 'cat');
        assert_field_error($b, 'vet_email', 'Please fill this in', 'cat');
        assert_no_field_error($b, 'patient_breed', 'cat'); // optional and untouched
        assert_contains($focus, $b, 'the page must move the cursor to the first problem');
        assert_true(strpos($b, 'class="reattach-note"') === false, 'the prescription message already says to attach it, so no extra note');
        $giveBack();
    });

    run_test('cat-owner form: a field that is too long says how long it may be', function () use ($postCat, $giveBack) {
        $giveBack();
        $post = $postCat(['owner_full_name' => str_repeat('a', 151)] + ['prescription' => new CURLFile(__DIR__ . '/../fixtures/tiny.png', 'image/png', 'p.png')], 'cookies-pf-cat2.txt');
        assert_field_error($post['body'], 'owner_full_name', 'Too long - 150 characters at most', 'cat');
        // A reload empties file inputs: when the prescription itself is not the problem, say so.
        assert_contains('<p class="reattach-note"><strong>Please attach your files again.</strong> Your browser clears chosen files when a form reloads', $post['body']);
        assert_contains('your prescription or any other documents', $post['body']);
        $giveBack();
    });

    run_test('cat-owner form: a clean first visit shows no messages and no focus script', function () use ($env, $focus) {
        $get = http_request('GET', $env->baseUrl . '/for-cat-owners/index.php', ['cookie_jar' => $env->tmpDir . '/cookies-pf-cat3.txt']);
        assert_true(strpos($get['body'], 'class="field-error"') === false, 'no field message on a fresh page');
        assert_true(strpos($get['body'], $focus) === false, 'no focus script on a fresh page');
        assert_true(strpos($get['body'], 'class="reattach-note"') === false, 'no re-attach note on a fresh page');
    });

    run_test('cat-owner form: the consent tick survives a failed submit', function () use ($postCat, $giveBack) {
        $giveBack();
        $post = $postCat(['owner_email' => 'broken', 'consent' => '1'], 'cookies-pf-cat4.txt');
        assert_field_error($post['body'], 'owner_email', 'Enter a valid email address', 'cat');
        assert_true((bool) preg_match('~name="consent" id="consent" value="1"[^>]*\schecked~', $post['body']), 'the consent box must stay ticked');
        $giveBack();
    });

    run_test('cat-owner form: there is no "send me updates" tick (nothing ever acted on it), and a crafted one records nothing', function () use ($env, $postCat, $giveBack, $catBase) {
        $giveBack();
        $get = http_request('GET', $env->baseUrl . '/for-cat-owners/index.php', ['cookie_jar' => $env->tmpDir . '/cookies-pf-cat8.txt']);
        assert_true(strpos($get['body'], 'marketing_consent') === false, 'the updates checkbox must not be on the page');
        assert_true(strpos($get['body'], 'receive occasional updates') === false, 'no promise of updates on the page');
        $email = 'no.marketing@example.test';
        $post = $postCat(array_merge($catBase, ['owner_email' => $email, 'marketing_consent' => '1', 'prescription' => new CURLFile(__DIR__ . '/../fixtures/tiny.png', 'image/png', 'rx.png')]), 'cookies-pf-cat9.txt');
        assert_equal(302, $post['status'], 'the request itself goes through');
        $caseId = (int) $env->scalar('SELECT id FROM gs_requests WHERE owner_email = ? ORDER BY id DESC LIMIT 1', [$email]);
        assert_true($caseId > 0);
        $types = $env->pdo()->prepare('SELECT consent_type FROM consent_records WHERE gs_request_id = ? ORDER BY id');
        $types->execute([$caseId]);
        assert_equal(['case_processing'], $types->fetchAll(PDO::FETCH_COLUMN), 'only the consent to process the case is recorded');
        $giveBack();
    });

    run_test('cat-owner form: a refused upload is explained under THAT file and still counts against the hourly allowance', function () use ($env, $postCat, $giveBack, $txt, $png, $catBase) {
        $giveBack();
        $count = fn () => (int) $env->scalar("SELECT COUNT(*) FROM rate_limit_hits WHERE bucket LIKE 'cat_owner_submit:%'");
        $before = $count();
        $post = $postCat($catBase + ['prescription' => new CURLFile($txt, 'text/plain', 'rx.txt')], 'cookies-pf-cat5.txt');
        assert_contains('Please check the highlighted fields', $post['body']);
        assert_true((bool) preg_match('~<span class="field-error" id="err-prescription">[^<]+</span>~', $post['body']), 'the reason must be printed under the prescription');
        assert_true(strpos($post['body'], 'A prescription upload is required') === false, 'a refused file is not "missing"');
        assert_equal($before + 1, $count(), 'upload failures keep using the hourly allowance (field typos do not)');

        $giveBack();
        $post = $postCat($catBase + ['prescription' => new CURLFile($png, 'image/png', 'rx.png'), 'supporting_1' => new CURLFile($txt, 'text/plain', 'lab.txt')], 'cookies-pf-cat6.txt');
        assert_true((bool) preg_match('~<span class="field-error" id="err-supporting_1">[^<]+</span>~', $post['body']), 'the reason must be printed under supporting document 1');
        assert_no_field_error($post['body'], 'prescription', 'cat');
        $giveBack();
    });

    run_test('cat-owner form: ordinary field typos do not use up the hourly allowance', function () use ($env, $postCat, $giveBack) {
        $giveBack();
        $postCat(['owner_email' => 'broken'], 'cookies-pf-cat7.txt');
        assert_equal(0, (int) $env->scalar("SELECT COUNT(*) FROM rate_limit_hits WHERE bucket LIKE 'cat_owner_submit:%'"));
        $giveBack();
    });

    run_test('vet application form: messages under the fields, the certificate and the website are explained, the heading is truthful', function () use ($env, $giveBack, $txt) {
        $giveBack();
        $jar = $env->tmpDir . '/cookies-pf-vet1.txt';
        $get = http_request('GET', $env->baseUrl . '/for-veterinarians/apply/index.php', ['cookie_jar' => $jar]);
        assert_true(strpos($get['body'], 'Verification documents (optional)') === false, 'the heading must not call the required certificate optional');
        assert_contains('The registration certificate is required', $get['body']);
        $post = http_request('POST', $env->baseUrl . '/for-veterinarians/apply/index.php', [
            'cookie_jar' => $jar,
            'body' => ['csrf_token' => extract_csrf($get['body']), 'ott' => extract_ott($get['body']), 'professional_email' => 'nope', 'mobile' => 'abc',
                'clinic_website' => 'not a url', 'year_qualified' => '1800', 'full_name' => str_repeat('b', 151)],
        ]);
        $b = $post['body'];
        assert_field_error($b, 'full_name', 'Too long - 150 characters at most', 'vet apply');
        assert_field_error($b, 'professional_email', 'Enter a valid email address', 'vet apply');
        assert_field_error($b, 'mobile', 'Enter a valid phone number', 'vet apply');
        assert_field_error($b, 'clinic_website', 'Enter a valid URL', 'vet apply');
        assert_field_error($b, 'year_qualified', 'Enter a valid year', 'vet apply');
        assert_field_error($b, 'registration_number', 'Please fill this in', 'vet apply');
        assert_field_error($b, 'registration_certificate', 'Please attach your veterinary registration certificate', 'vet apply');
        assert_true(strpos($b, 'class="reattach-note"') === false, 'the certificate message already says to attach it');
        assert_field_error($b, 'consent', 'Consent is required to submit this application', 'vet apply');
        assert_true((bool) preg_match('~<label class="field has-error">\s*<span class="lbl">Website~', $b), 'the website field had a message but no highlight at all');
        assert_contains('querySelector(\'[aria-invalid="true"]\')', $b);
        $giveBack();
    });

    run_test('vet application form: the re-attach note names the certificate when something else is wrong', function () use ($env, $giveBack) {
        $giveBack();
        $jar = $env->tmpDir . '/cookies-pf-vet2.txt';
        $get = http_request('GET', $env->baseUrl . '/for-veterinarians/apply/index.php', ['cookie_jar' => $jar]);
        $post = http_request('POST', $env->baseUrl . '/for-veterinarians/apply/index.php', [
            'cookie_jar' => $jar,
            'body' => ['csrf_token' => extract_csrf($get['body']), 'ott' => extract_ott($get['body']), 'professional_email' => 'nope', 'consent' => '1',
                'registration_certificate' => new CURLFile(__DIR__ . '/../fixtures/tiny.png', 'image/png', 'cert.png')],
        ]);
        assert_contains('<p class="reattach-note"><strong>Please attach your files again.</strong>', $post['body']);
        assert_contains('your registration certificate or any other documents', $post['body']);
        assert_true((bool) preg_match('~name="consent" id="consent" value="1"[^>]*\schecked~', $post['body']), 'the consent tick must survive a failed submit on this form too');
        $giveBack();
    });

    // The vet-portal request form is covered in 80_vet_portal.php, right after the vet signs in (that file later suspends
    // and re-activates the account, so the signed-in session is only usable there).
};
