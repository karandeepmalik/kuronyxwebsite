<?php
return function (TestEnv $env): void {
    run_test('changing a case status never sends an email automatically', function () use ($env) {
        $id  = $env->shared['gsRequestId'];
        $jar = $env->cookieJarStaff;
        $countBefore = (int) $env->scalar('SELECT COUNT(*) FROM case_emails WHERE gs_request_id = ?', [$id]);

        $view = http_request('GET', $env->baseUrl . "/admin/gs-requests/view.php?id={$id}", ['cookie_jar' => $jar]);
        $csrf = extract_csrf($view['body']);
        $post = http_request('POST', $env->baseUrl . "/admin/gs-requests/view.php?id={$id}", [
            'cookie_jar' => $jar,
            'body' => ['csrf_token' => $csrf, 'action' => 'change_status', 'new_status' => 'approved', 'status_note' => ''],
        ]);
        assert_contains('Nothing is emailed automatically', $post['body']);
        assert_true(strpos($post['body'], 'notified by email') === false, 'no automatic notification copy should appear');

        assert_equal($countBefore, (int) $env->scalar('SELECT COUNT(*) FROM case_emails WHERE gs_request_id = ?', [$id]), 'no email should have been logged just from a status change');
        $auditCount = (int) $env->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'notification_sent' AND entity_type = 'gs_request' AND entity_id = ?", [$id]);
        assert_equal(0, $auditCount, 'there is no automatic notification action left to audit');
    });

    run_test('the composer rejects a sender that is not on the verified list', function () use ($env) {
        $id  = $env->shared['gsRequestId'];
        $jar = $env->cookieJarStaff;
        $view = http_request('GET', $env->baseUrl . "/admin/gs-requests/view.php?id={$id}", ['cookie_jar' => $jar]);
        $csrf = extract_csrf($view['body']);
        $post = http_request('POST', $env->baseUrl . "/admin/gs-requests/view.php?id={$id}", [
            'cookie_jar' => $jar,
            'body' => [
                'csrf_token' => $csrf, 'action' => 'send_email', 'sender' => 'attacker@evil.test',
                'recipient' => 'priya@example.test', 'subject' => 'x', 'body' => 'y',
            ],
        ]);
        assert_contains('valid sender address', $post['body']);
    });

    run_test('the composer rejects a recipient that is not on file for the case', function () use ($env) {
        $id  = $env->shared['gsRequestId'];
        $jar = $env->cookieJarStaff;
        $view = http_request('GET', $env->baseUrl . "/admin/gs-requests/view.php?id={$id}", ['cookie_jar' => $jar]);
        $csrf = extract_csrf($view['body']);
        $post = http_request('POST', $env->baseUrl . "/admin/gs-requests/view.php?id={$id}", [
            'cookie_jar' => $jar,
            'body' => [
                'csrf_token' => $csrf, 'action' => 'send_email', 'sender' => 'hello@kuronyx.in',
                'recipient' => 'someone-else@example.test', 'subject' => 'x', 'body' => 'y',
            ],
        ]);
        assert_contains('on file for this case', $post['body']);
    });

    run_test('staff can pick a template, edit it, and send it manually with a chosen sender', function () use ($env) {
        $id  = $env->shared['gsRequestId'];
        $jar = $env->cookieJarStaff;
        $view = http_request('GET', $env->baseUrl . "/admin/gs-requests/view.php?id={$id}", ['cookie_jar' => $jar]);
        $csrf = extract_csrf($view['body']);
        assert_contains('Ready for Dispatch', $view['body'], 'the template dropdown should offer a per-status option');

        $post = http_request('POST', $env->baseUrl . "/admin/gs-requests/view.php?id={$id}", [
            'cookie_jar' => $jar,
            'body' => [
                'csrf_token' => $csrf, 'action' => 'send_email', 'sender' => 'ops@kuronyx.in',
                'recipient' => 'priya@example.test', 'subject' => 'Following up', 'body' => 'Just checking in on your prescription.',
            ],
        ]);
        assert_contains('Email sent', $post['body']);

        $stmt = $env->pdo()->prepare("SELECT * FROM case_emails WHERE gs_request_id = ? AND subject = 'Following up'");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        assert_true($row !== false, 'the composed email should be logged');
        assert_equal('sent', $row['delivery_status']);
        assert_equal('ops@kuronyx.in', $row['sender'], 'the chosen sender should be recorded, not just the legacy default');
    });

    run_test('marking a case update "no email needed" logs it without sending anything', function () use ($env) {
        $id  = $env->shared['gsRequestId'];
        $jar = $env->cookieJarStaff;
        $countBefore = (int) $env->scalar('SELECT COUNT(*) FROM case_emails WHERE gs_request_id = ?', [$id]);

        $view = http_request('GET', $env->baseUrl . "/admin/gs-requests/view.php?id={$id}", ['cookie_jar' => $jar]);
        $csrf = extract_csrf($view['body']);
        $post = http_request('POST', $env->baseUrl . "/admin/gs-requests/view.php?id={$id}", [
            'cookie_jar' => $jar,
            'body' => ['csrf_token' => $csrf, 'action' => 'no_email_needed'],
        ]);
        assert_contains('Noted', $post['body']);
        assert_equal($countBefore, (int) $env->scalar('SELECT COUNT(*) FROM case_emails WHERE gs_request_id = ?', [$id]));

        $auditCount = (int) $env->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'email_not_needed' AND entity_type = 'gs_request' AND entity_id = ?", [$id]);
        assert_true($auditCount >= 1, 'the decision not to email should still be audited');
    });

    run_test('approving a vet application does not send an email automatically', function () use ($env) {
        $id = $env->shared['vetApplicationId'];
        $auditCount = (int) $env->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'notification_sent' AND entity_type = 'vet_application' AND entity_id = ?", [$id]);
        assert_equal(0, $auditCount, 'approve() no longer has any automatic-notify code path to audit');

        $notes = (string) $env->scalar('SELECT internal_notes FROM vet_applications WHERE id = ?', [$id]);
        assert_true(strpos($notes, 'notified by email') === false, 'no automatic notification should have been logged to internal notes');
    });

    run_test('generating an activation link reveals a fresh link that can then be sent as a template email', function () use ($env) {
        $id  = $env->shared['vetApplicationId'];
        $jar = $env->cookieJarStaff;
        $view = http_request('GET', $env->baseUrl . "/admin/veterinary-applications/view.php?id={$id}", ['cookie_jar' => $jar]);
        $csrf = extract_csrf($view['body']);

        $gen = http_request('POST', $env->baseUrl . "/admin/veterinary-applications/view.php?id={$id}", [
            'cookie_jar' => $jar,
            'body' => ['csrf_token' => $csrf, 'action' => 'generate_activation_link', 'note' => ''],
        ]);
        assert_contains('fresh activation link', $gen['body']);
        assert_true((bool) preg_match('#https://kuronyx\.in/for-veterinarians/activate\.php\?token=[a-f0-9]+#', $gen['body'], $m), 'the compose panel should now contain a real activation link');
        $link = $m[0];

        $csrf2 = extract_csrf($gen['body']);
        $send = http_request('POST', $env->baseUrl . "/admin/veterinary-applications/view.php?id={$id}", [
            'cookie_jar' => $jar,
            'body' => [
                'csrf_token' => $csrf2, 'action' => 'send_email', 'sender' => 'hello@kuronyx.in',
                'recipient' => 'asha.verma@example.test', 'subject' => 'Your Kuronyx veterinary account is approved',
                'body' => "Set your password here:\n{$link}\n\nReference: VA-{$id}",
            ],
        ]);
        assert_contains('Email sent', $send['body']);

        $email = $env->lastDryRunEmailTo('asha.verma@example.test');
        assert_true($email !== null, 'the manually-sent activation email should be logged');
        assert_contains('/for-veterinarians/activate.php?token=', $email['body']);
        assert_equal('hello@kuronyx.in', $email['from']);

        $notes = (string) $env->scalar('SELECT internal_notes FROM vet_applications WHERE id = ?', [$id]);
        assert_contains('Emailed applicant', $notes);
    });

    run_test('the vet-application composer also rejects an unverified sender and an off-file recipient', function () use ($env) {
        $id  = $env->shared['vetApplicationId'];
        $jar = $env->cookieJarStaff;
        $view = http_request('GET', $env->baseUrl . "/admin/veterinary-applications/view.php?id={$id}", ['cookie_jar' => $jar]);
        $csrf = extract_csrf($view['body']);
        $badSender = http_request('POST', $env->baseUrl . "/admin/veterinary-applications/view.php?id={$id}", [
            'cookie_jar' => $jar,
            'body' => ['csrf_token' => $csrf, 'action' => 'send_email', 'sender' => 'attacker@evil.test', 'recipient' => 'asha.verma@example.test', 'subject' => 'x', 'body' => 'y'],
        ]);
        assert_contains('valid sender address', $badSender['body']);

        $csrf2 = extract_csrf($badSender['body']);
        $badRecipient = http_request('POST', $env->baseUrl . "/admin/veterinary-applications/view.php?id={$id}", [
            'cookie_jar' => $jar,
            'body' => ['csrf_token' => $csrf2, 'action' => 'send_email', 'sender' => 'hello@kuronyx.in', 'recipient' => 'someone-else@example.test', 'subject' => 'x', 'body' => 'y'],
        ]);
        assert_contains('on file for this application', $badRecipient['body']);
    });

    run_test('rejecting a vet application records the outcome but does not email automatically; "no email needed" can close it out', function () use ($env) {
        $id  = $env->shared['vetApplicationId'];
        $jar = $env->cookieJarStaff;
        $view = http_request('GET', $env->baseUrl . "/admin/veterinary-applications/view.php?id={$id}", ['cookie_jar' => $jar]);
        $csrf = extract_csrf($view['body']);
        $post = http_request('POST', $env->baseUrl . "/admin/veterinary-applications/view.php?id={$id}", [
            'cookie_jar' => $jar,
            'body' => ['csrf_token' => $csrf, 'action' => 'reject', 'note' => 'Registration could not be verified.'],
        ]);
        assert_contains('Application rejected', $post['body']);
        assert_true(strpos($post['body'], 'notified by email') === false);

        $csrf2 = extract_csrf($post['body']);
        $noEmail = http_request('POST', $env->baseUrl . "/admin/veterinary-applications/view.php?id={$id}", [
            'cookie_jar' => $jar,
            'body' => ['csrf_token' => $csrf2, 'action' => 'no_email_needed', 'note' => ''],
        ]);
        assert_contains('Noted', $noEmail['body']);
        assert_contains('no email needed', strtolower((string) $env->scalar('SELECT internal_notes FROM vet_applications WHERE id = ?', [$id])));

        $auditCount = (int) $env->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'email_not_needed' AND entity_type = 'vet_application' AND entity_id = ?", [$id]);
        assert_true($auditCount >= 1);
    });
};
