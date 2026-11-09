<?php
// The server's error log sits OUTSIDE the data-erasure process, so an email address written there outlives the erasure
// of the case it belongs to (a Karandeep review comment). Log lines name the case and the log row instead (GS-123,
// case_emails #45), and anything we do not control - an exception message, whatever Brevo sent back, which can echo the
// address - goes through log_safe() first. This file checks the helper, scans the code so a new log line cannot slip
// past it, and reads the REAL server log after real sends.
return function (TestEnv $env): void {
    $jar = $env->cookieJarStaff;

    run_test('log_safe() takes email addresses out of what is logged, and cuts very long text', function () use ($env) {
        require_once $env->root . '/public_html/includes/log-safe.php';
        assert_equal('invalid: [email]', log_safe('invalid: owner@example.test'));
        assert_equal('[email] and [email]', log_safe('Owner.Name+tag@Mail.Example.CO.IN and vet_2@clinic-x.example.test'));
        assert_equal('{"code":"invalid_parameter","message":"email address \'[email]\' is not valid"}', log_safe('{"code":"invalid_parameter","message":"email address \'owner@example.test\' is not valid"}'), 'a Brevo style error that echoes the address');
        assert_equal('case email #45 (GS-123) failed: HTTP 400', log_safe('case email #45 (GS-123) failed: HTTP 400'), 'case numbers and row ids are untouched');
        assert_equal('no address here, just a handle @someone and 5@6', log_safe('no address here, just a handle @someone and 5@6'), 'things that are not addresses are left alone');
        $long = log_safe(str_repeat('a', 600));
        assert_equal(500 + strlen('...[cut]'), strlen($long));
        assert_true(strpos(log_safe(str_repeat('x', 490) . ' owner@example.test'), '@') === false, 'an address near the cut is still removed, not half-printed');
        assert_equal('', log_safe(''));
    });

    run_test('every error_log() line that carries an exception message or a Brevo reply goes through log_safe(), and none names an address variable', function () use ($env) {
        $root = $env->root . '/public_html';
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        $seen = 0;
        foreach ($it as $f) {
            if ($f->getExtension() !== 'php') continue;
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root)));
            foreach (preg_split('/\r?\n/', (string) file_get_contents($f->getPathname())) as $n => $line) {
                if (strpos($line, 'error_log(') === false || preg_match('#^\s*(//|\*)#', $line)) continue;
                $seen++;
                $where = "{$rel}:" . ($n + 1);
                if (preg_match('/->getMessage\(\)|\$response/', $line)) {
                    assert_true(strpos($line, 'log_safe(') !== false, "{$where}: an exception message or API reply is logged without log_safe(): " . trim($line));
                }
                assert_true(!preg_match('/\$(recipient|email|toEmail|ownerEmail|vetEmail|clinicEmail|professionalEmail)\b|\$deferredEmail|\$_POST|\$_SESSION/', $line), "{$where}: an error_log line names an address (or form data) variable: " . trim($line));
            }
        }
        assert_true($seen >= 15, 'the scan found the error_log lines (' . $seen . ')');
    });

    $log = function () use ($env): string { return (string) @file_get_contents($env->logPath); };

    run_test('real sends that fail or are unconfirmed leave the case number and row id in the server log, never the recipient\'s address', function () use ($env, $jar, $log) {
        $pdo = $env->pdo();
        $ids = [];
        foreach (['send-fails@example.test', 'send-unconfirmed@example.test'] as $addr) {
            $pdo->prepare(
                "INSERT INTO gs_requests (source, owner_full_name, owner_email, owner_phone, owner_address, owner_city, owner_state, owner_pin, patient_name, requested_formulation, status)
                 VALUES ('cat_owner', 'Log Safe Owner', ?, '9000000003', 'x', 'x', 'x', '000000', 'LogCat', 'oral', 'submitted')"
            )->execute([$addr]);
            $caseId = (int) $env->scalar('SELECT id FROM gs_requests WHERE owner_email = ? ORDER BY id DESC LIMIT 1', [$addr]);
            $url = $env->baseUrl . "/admin/gs-requests/view.php?id={$caseId}";
            $html = http_request('GET', $url, ['cookie_jar' => $jar])['body'];
            http_request('POST', $url, ['cookie_jar' => $jar, 'body' => [
                'csrf_token' => extract_csrf($html), 'ott' => extract_ott($html), 'action' => 'send_email',
                'sender' => 'ops@kuronyx.in', 'recipient' => $addr, 'subject' => 'Log check', 'body' => 'Checking the log.',
            ]]);
            $rowId = (int) $env->scalar('SELECT id FROM case_emails WHERE gs_request_id = ? ORDER BY id DESC LIMIT 1', [$caseId]);
            $ids[$addr] = [$caseId, $rowId];
        }
        $text = $log();
        foreach ($ids as $addr => [$caseId, $rowId]) {
            $status = $addr === 'send-fails@example.test' ? 'failed' : 'unknown';
            assert_contains("[mailer] case email #{$rowId} (GS-{$caseId}) {$status}:", $text, 'the line names the case and the row, and what happened');
            assert_true(strpos($text, $addr) === false, "the server log must not contain {$addr}");
        }
        assert_contains('Simulated send failure for [email]', $text, 'the address the exception echoed was taken out');
        assert_contains('Simulated unconfirmed send for [email]', $text);
    });

    run_test('the veterinary application email and the staff invitation keep addresses out of the log too', function () use ($env, $jar, $log) {
        $pdo = $env->pdo();
        $addr = 'send-fails@example.test';
        $uniq = bin2hex(random_bytes(3));
        $pdo->prepare(
            "INSERT INTO vet_applications (full_name, professional_email, mobile, registration_number, registration_state, qualification, year_qualified,
                 practice_type, clinic_name, clinic_address, clinic_city, clinic_state, clinic_pin, clinic_phone, status)
             VALUES ('Dr Log Safe', ?, '9000055556', ?, 'Maharashtra', 'BVSc', 2017, 'Small animal', 'Log Clinic', '1 Rd', 'Pune', 'MH', '411001', '02011115555', 'pending')"
        )->execute([$addr, "MH-VET-{$uniq}"]);
        $appId = (int) $env->scalar('SELECT id FROM vet_applications WHERE registration_number = ?', ["MH-VET-{$uniq}"]);
        $url = $env->baseUrl . "/admin/veterinary-applications/view.php?id={$appId}";
        $html = http_request('GET', $url, ['cookie_jar' => $jar])['body'];
        http_request('POST', $url, ['cookie_jar' => $jar, 'body' => [
            'csrf_token' => extract_csrf($html), 'ott' => extract_ott($html), 'action' => 'send_email',
            'sender' => 'ops@kuronyx.in', 'recipient' => $addr, 'subject' => 'Log check', 'body' => 'Checking the log.',
        ]]);

        $get = http_request('GET', $env->baseUrl . '/admin/staff/new.php', ['cookie_jar' => $jar]);
        http_request('POST', $env->baseUrl . '/admin/staff/new.php', ['cookie_jar' => $jar, 'body' => [
            'csrf_token' => extract_csrf($get['body']), 'ott' => extract_ott($get['body']), 'name' => 'Log Safe Invitee', 'email' => $addr, 'role' => 'pharmacy_staff',
        ]]);
        $pdo->prepare('DELETE FROM staff_users WHERE email = ?')->execute([$addr]);

        $text = $log();
        assert_contains("[mailer] application email (VA-{$appId}) failed or unconfirmed:", $text);
        assert_contains('[staff-invite] email to a new staff account failed:', $text);
        assert_true(strpos($text, $addr) === false, "the server log must not contain {$addr}");
    });
};
