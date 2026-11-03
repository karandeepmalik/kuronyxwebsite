<?php
// The proof that a veterinary applicant consented (a Karandeep review comment). For cases it went into consent_records
// with the IP address and browser; for applications it lived only in the audit log's metadata, which holds no browser
// details and is not meant to be a consent register. It now has its own record, vet_application_consents, saved in
// the same transaction as the application, and erased with it (69_vet_application_erasure.php).
return function (TestEnv $env): void {
    $png = __DIR__ . '/../fixtures/tiny.png';
    $giveBack = function () use ($env) {
        $env->pdo()->exec("DELETE FROM rate_limit_hits WHERE bucket LIKE 'vet_apply_submit:%'");
    };
    $fields = fn (string $email, array $extra = []): array => array_merge([
        'full_name' => 'Dr. Consent Check', 'professional_email' => $email, 'mobile' => '9876500000',
        'registration_number' => 'MH-VET-5555', 'registration_state' => 'Maharashtra', 'registration_country' => 'India',
        'qualification' => 'BVSc & AH', 'year_qualified' => '2016', 'practice_type' => 'Independent practice',
        'clinic_name' => 'Consent Clinic', 'clinic_address' => '3 Road', 'clinic_city' => 'Pune', 'clinic_state' => 'Maharashtra',
        'clinic_pin' => '411001', 'clinic_country' => 'India', 'clinic_phone' => '02012345678', 'consent' => '1',
    ], $extra);
    $submit = function (array $body, string $jarName, string $userAgent = 'ConsentTestBrowser/1.0') use ($env, $png): array {
        $jar = $env->tmpDir . '/' . $jarName;
        $get = http_request('GET', $env->baseUrl . '/for-veterinarians/apply/index.php', ['cookie_jar' => $jar]);
        return http_request('POST', $env->baseUrl . '/for-veterinarians/apply/index.php', [
            'cookie_jar' => $jar,
            'headers' => ['User-Agent: ' . $userAgent],
            'body' => array_merge(['csrf_token' => extract_csrf($get['body']), 'ott' => extract_ott($get['body']), 'registration_certificate' => new CURLFile($png, 'image/png', 'cert.png')], $body),
        ]);
    };
    $consentRows = fn (): int => (int) $env->scalar('SELECT COUNT(*) FROM vet_application_consents');

    run_test('an application saves its consent record: type, Privacy Policy version, IP address and browser', function () use ($env, $fields, $submit, $giveBack) {
        $giveBack();
        $email = 'consent.proof@example.test';
        $r = $submit($fields($email), 'cookies-vetconsent-ok.txt', 'ConsentTestBrowser/1.0 (proof)');
        assert_equal(302, $r['status']);
        $appId = (int) $env->scalar('SELECT id FROM vet_applications WHERE professional_email = ?', [$email]);
        assert_true($appId > 0, 'the application was saved');

        $rows = $env->pdo()->prepare('SELECT * FROM vet_application_consents WHERE application_id = ?');
        $rows->execute([$appId]);
        $rows = $rows->fetchAll(PDO::FETCH_ASSOC);
        assert_equal(1, count($rows), 'exactly one consent record per application');
        $c = $rows[0];
        assert_equal('application_processing', $c['consent_type']);
        $policy = dirname(__DIR__, 2) . '/public_html/Privacy Policy.html';
        assert_equal('pp-' . substr(hash_file('sha256', $policy), 0, 16), $c['consent_text_version'], 'it names the exact Privacy Policy text that was on the site');
        assert_equal('127.0.0.1', $c['ip_address']);
        assert_equal('ConsentTestBrowser/1.0 (proof)', $c['user_agent'], 'the browser is recorded, which the audit entry never held');
        assert_true($c['created_at'] !== null);

        // The audit entry stays (who did what, when) but no longer carries the IP: that lives in the record above.
        $meta = json_decode((string) $env->scalar("SELECT metadata_json FROM audit_log WHERE action = 'application_submitted' AND entity_id = ?", [$appId]), true);
        assert_equal(true, $meta['consent_given']);
        assert_equal($c['consent_text_version'], $meta['consent_version']);
        assert_true(!array_key_exists('source_ip', $meta), 'the IP address is kept in one place only, the consent record');
    });

    run_test('a browser string longer than the column is cut, not refused', function () use ($env, $fields, $submit, $giveBack) {
        $giveBack();
        $email = 'consent.longua@example.test';
        $r = $submit($fields($email), 'cookies-vetconsent-ua.txt', str_repeat('A', 400));
        assert_equal(302, $r['status'], 'a very long User-Agent must not break the submission');
        $appId = (int) $env->scalar('SELECT id FROM vet_applications WHERE professional_email = ?', [$email]);
        assert_equal(255, strlen((string) $env->scalar('SELECT user_agent FROM vet_application_consents WHERE application_id = ?', [$appId])));
    });

    run_test('a refused submission leaves no consent record (and so does one with the box unticked)', function () use ($env, $fields, $submit, $giveBack, $consentRows) {
        $giveBack();
        $before = $consentRows();
        $r = $submit($fields('consent.refused@example.test', ['full_name' => '']), 'cookies-vetconsent-bad.txt');
        assert_equal(200, $r['status'], 'a missing name is answered on the page');
        $r = $submit($fields('consent.unticked@example.test', ['consent' => '']), 'cookies-vetconsent-unticked.txt');
        assert_equal(200, $r['status']);
        assert_contains('Consent is required', $r['body']);
        assert_equal($before, $consentRows(), 'no record without a saved, consented application');
        assert_equal(0, (int) $env->scalar("SELECT COUNT(*) FROM vet_applications WHERE professional_email IN ('consent.refused@example.test','consent.unticked@example.test')"));
    });

    run_test('the consent record and the application are saved together: if the record cannot be saved, no application is left behind', function () use ($env, $fields, $submit, $giveBack) {
        if ($env->isMysql) skip_test('SQLite-only: the trigger that makes the consent insert fail');
        $giveBack();
        $pdo = $env->pdo();
        $pdo->exec("CREATE TRIGGER trg_test_consent_fails BEFORE INSERT ON vet_application_consents BEGIN SELECT RAISE(ABORT, 'consent insert refused'); END");
        try {
            $email = 'consent.atomic@example.test';
            $r = $submit($fields($email), 'cookies-vetconsent-atomic.txt');
            assert_true($r['status'] !== 302, 'the submission must not be reported as received');
            assert_equal(0, (int) $env->scalar('SELECT COUNT(*) FROM vet_applications WHERE professional_email = ?', [$email]), 'no application without its consent proof');
            assert_equal(0, (int) $env->scalar('SELECT COUNT(*) FROM vet_application_documents d JOIN vet_applications a ON a.id = d.application_id WHERE a.professional_email = ?', [$email]));
        } finally {
            $pdo->exec('DROP TRIGGER trg_test_consent_fails');
            $giveBack();
        }
    });
};
