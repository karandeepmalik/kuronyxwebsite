<?php
return function (TestEnv $env): void {
    run_test('changing a case status never sends an email automatically', function () use ($env) {
        $id  = $env->shared['gsRequestId'];
        $jar = $env->cookieJarStaff;
        $countBefore = (int) $env->scalar('SELECT COUNT(*) FROM case_emails WHERE gs_request_id = ?', [$id]);

        $view = http_request('GET', $env->baseUrl . "/admin/gs-requests/view.php?id={$id}", ['cookie_jar' => $jar]);
        $csrf = extract_csrf($view['body']);
        $lockVersion = $env->scalar('SELECT lock_version FROM gs_requests WHERE id = ?', [$id]);
        $post = http_request('POST', $env->baseUrl . "/admin/gs-requests/view.php?id={$id}", [
            'cookie_jar' => $jar,
            'body' => ['csrf_token' => $csrf, 'action' => 'change_status', 'new_status' => 'approved', 'status_note' => '', 'expected_lock_version' => $lockVersion],
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
        $ott  = extract_ott($view['body']);
        $post = http_request('POST', $env->baseUrl . "/admin/gs-requests/view.php?id={$id}", [
            'cookie_jar' => $jar,
            'body' => [
                'csrf_token' => $csrf, 'ott' => $ott, 'action' => 'send_email', 'sender' => 'attacker@evil.test',
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
        $ott  = extract_ott($view['body']);
        $post = http_request('POST', $env->baseUrl . "/admin/gs-requests/view.php?id={$id}", [
            'cookie_jar' => $jar,
            'body' => [
                'csrf_token' => $csrf, 'ott' => $ott, 'action' => 'send_email', 'sender' => 'hello@kuronyx.in',
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
        $ott  = extract_ott($view['body']);
        assert_contains('Ready for Dispatch', $view['body'], 'the template dropdown should offer a per-status option');

        $post = http_request('POST', $env->baseUrl . "/admin/gs-requests/view.php?id={$id}", [
            'cookie_jar' => $jar,
            'body' => [
                'csrf_token' => $csrf, 'ott' => $ott, 'action' => 'send_email', 'sender' => 'ops@kuronyx.in',
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

    run_test('resubmitting the same send_email form (double-click / F5) sends at most once', function () use ($env) {
        // Regression test for the email-double-send fix: a double-click or a stale page
        // reload resubmitting the exact same POST now embeds a one-time token
        // (one_time_field()/consume_one_time_token() in auth.php) that's only ever valid
        // for the first submission of a given form render — the second one is rejected
        // outright, before send_case_email() (and therefore the real Brevo call) is ever
        // reached.
        $id  = $env->shared['gsRequestId'];
        $jar = $env->cookieJarStaff;
        $countBefore = (int) $env->scalar('SELECT COUNT(*) FROM case_emails WHERE gs_request_id = ?', [$id]);

        $view = http_request('GET', $env->baseUrl . "/admin/gs-requests/view.php?id={$id}", ['cookie_jar' => $jar]);
        $csrf = extract_csrf($view['body']);
        $ott  = extract_ott($view['body']);
        $body = [
            'csrf_token' => $csrf, 'ott' => $ott, 'action' => 'send_email', 'sender' => 'ops@kuronyx.in',
            'recipient' => 'priya@example.test', 'subject' => 'Double-send check', 'body' => 'This must only ever be logged once.',
        ];

        $first = http_request('POST', $env->baseUrl . "/admin/gs-requests/view.php?id={$id}", ['cookie_jar' => $jar, 'body' => $body]);
        assert_contains('Email sent', $first['body']);

        // Same jar (same session), same token — exactly what a double-click fires.
        $second = http_request('POST', $env->baseUrl . "/admin/gs-requests/view.php?id={$id}", ['cookie_jar' => $jar, 'body' => $body]);
        assert_contains('already submitted', $second['body']);

        $countAfter = (int) $env->scalar("SELECT COUNT(*) FROM case_emails WHERE gs_request_id = ? AND subject = 'Double-send check'", [$id]);
        assert_equal(1, $countAfter, 'the resubmission must not have logged (or sent) a second email');
    });

    run_test('marking a case update "no email needed" logs it without sending anything', function () use ($env) {
        $id  = $env->shared['gsRequestId'];
        $jar = $env->cookieJarStaff;
        $countBefore = (int) $env->scalar('SELECT COUNT(*) FROM case_emails WHERE gs_request_id = ?', [$id]);

        $view = http_request('GET', $env->baseUrl . "/admin/gs-requests/view.php?id={$id}", ['cookie_jar' => $jar]);
        $csrf = extract_csrf($view['body']);
        $ott  = extract_ott($view['body']);
        $post = http_request('POST', $env->baseUrl . "/admin/gs-requests/view.php?id={$id}", [
            'cookie_jar' => $jar,
            'body' => ['csrf_token' => $csrf, 'ott' => $ott, 'action' => 'no_email_needed'],
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
        $ott2  = extract_ott($gen['body']);
        $send = http_request('POST', $env->baseUrl . "/admin/veterinary-applications/view.php?id={$id}", [
            'cookie_jar' => $jar,
            'body' => [
                'csrf_token' => $csrf2, 'ott' => $ott2, 'action' => 'send_email', 'sender' => 'hello@kuronyx.in',
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

    run_test('sending a message containing an activation link that has since been replaced is rejected', function () use ($env) {
        // Regression test for the generate_activation_link race: generating a fresh link
        // always invalidates the previous one. If that happens between drafting a message
        // and actually sending it (two staff, or the same staff in another tab), the body
        // can still contain a dead link — this proves send_email re-checks the embedded
        // token against the account's current one at send time, not just when drafted.
        $id  = $env->shared['vetApplicationId'];
        $jar = $env->cookieJarStaff;

        $view = http_request('GET', $env->baseUrl . "/admin/veterinary-applications/view.php?id={$id}", ['cookie_jar' => $jar]);
        $csrf = extract_csrf($view['body']);
        $genA = http_request('POST', $env->baseUrl . "/admin/veterinary-applications/view.php?id={$id}", [
            'cookie_jar' => $jar,
            'body' => ['csrf_token' => $csrf, 'action' => 'generate_activation_link', 'note' => ''],
        ]);
        assert_true((bool) preg_match('#https://kuronyx\.in/for-veterinarians/activate\.php\?token=[a-f0-9]+#', $genA['body'], $m), 'expected a fresh link (A)');
        $staleLink = $m[0];

        // Regenerating invalidates the one just captured above.
        $csrf2 = extract_csrf($genA['body']);
        $genB = http_request('POST', $env->baseUrl . "/admin/veterinary-applications/view.php?id={$id}", [
            'cookie_jar' => $jar,
            'body' => ['csrf_token' => $csrf2, 'action' => 'generate_activation_link', 'note' => ''],
        ]);
        assert_true((bool) preg_match('#https://kuronyx\.in/for-veterinarians/activate\.php\?token=[a-f0-9]+#', $genB['body'], $m2), 'expected a fresh link (B)');
        $freshLink = $m2[0];
        assert_true($staleLink !== $freshLink, 'regenerating should produce a different token');

        // Sending the now-stale link (as if the compose box still had it from before B
        // was generated) must be refused.
        $csrf3 = extract_csrf($genB['body']);
        $ott3  = extract_ott($genB['body']);
        $staleSend = http_request('POST', $env->baseUrl . "/admin/veterinary-applications/view.php?id={$id}", [
            'cookie_jar' => $jar,
            'body' => [
                'csrf_token' => $csrf3, 'ott' => $ott3, 'action' => 'send_email', 'sender' => 'hello@kuronyx.in',
                'recipient' => 'asha.verma@example.test', 'subject' => 'Your Kuronyx veterinary account is approved',
                'body' => "Set your password here:\n{$staleLink}\n\nReference: VA-{$id}",
            ],
        ]);
        assert_contains('no longer valid', $staleSend['body']);
        assert_true(strpos($staleSend['body'], 'Email sent') === false, 'the stale link must not have been sent');

        // Sending the current, still-live link succeeds normally.
        $csrf4 = extract_csrf($staleSend['body']);
        $ott4  = extract_ott($staleSend['body']);
        $freshSend = http_request('POST', $env->baseUrl . "/admin/veterinary-applications/view.php?id={$id}", [
            'cookie_jar' => $jar,
            'body' => [
                'csrf_token' => $csrf4, 'ott' => $ott4, 'action' => 'send_email', 'sender' => 'hello@kuronyx.in',
                'recipient' => 'asha.verma@example.test', 'subject' => 'Your Kuronyx veterinary account is approved',
                'body' => "Set your password here:\n{$freshLink}\n\nReference: VA-{$id}",
            ],
        ]);
        assert_contains('Email sent', $freshSend['body']);
    });

    run_test('the vet-application composer also rejects an unverified sender and an off-file recipient', function () use ($env) {
        $id  = $env->shared['vetApplicationId'];
        $jar = $env->cookieJarStaff;
        $view = http_request('GET', $env->baseUrl . "/admin/veterinary-applications/view.php?id={$id}", ['cookie_jar' => $jar]);
        $csrf = extract_csrf($view['body']);
        $ott  = extract_ott($view['body']);
        $badSender = http_request('POST', $env->baseUrl . "/admin/veterinary-applications/view.php?id={$id}", [
            'cookie_jar' => $jar,
            'body' => ['csrf_token' => $csrf, 'ott' => $ott, 'action' => 'send_email', 'sender' => 'attacker@evil.test', 'recipient' => 'asha.verma@example.test', 'subject' => 'x', 'body' => 'y'],
        ]);
        assert_contains('valid sender address', $badSender['body']);

        $csrf2 = extract_csrf($badSender['body']);
        $ott2  = extract_ott($badSender['body']);
        $badRecipient = http_request('POST', $env->baseUrl . "/admin/veterinary-applications/view.php?id={$id}", [
            'cookie_jar' => $jar,
            'body' => ['csrf_token' => $csrf2, 'ott' => $ott2, 'action' => 'send_email', 'sender' => 'hello@kuronyx.in', 'recipient' => 'someone-else@example.test', 'subject' => 'x', 'body' => 'y'],
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
        assert_equal(
            'suspended',
            $env->scalar('SELECT status FROM vet_accounts WHERE vet_application_id = ?', [$id]),
            'rejecting an application must also lock out any portal account it had already provisioned'
        );

        $csrf2 = extract_csrf($post['body']);
        $noEmail = http_request('POST', $env->baseUrl . "/admin/veterinary-applications/view.php?id={$id}", [
            'cookie_jar' => $jar,
            'body' => ['csrf_token' => $csrf2, 'ott' => extract_ott($post['body']), 'action' => 'no_email_needed', 'note' => ''],
        ]);
        assert_contains('Noted', $noEmail['body']);
        assert_contains('no email needed', strtolower((string) $env->scalar('SELECT internal_notes FROM vet_applications WHERE id = ?', [$id])));

        $auditCount = (int) $env->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'email_not_needed' AND entity_type = 'vet_application' AND entity_id = ?", [$id]);
        assert_true($auditCount >= 1);
    });

    run_test('generate_activation_link refuses to mint a token once its application is no longer approved', function () use ($env) {
        // The application from the previous test is now rejected. Minting a token here
        // would produce a link that looks usable but can never work — activate.php also
        // requires the linked application to still be 'approved' — so this is now refused
        // at the source instead of relying on activate.php to quietly swallow a dead link.
        $id  = $env->shared['vetApplicationId'];
        $jar = $env->cookieJarStaff;
        $view = http_request('GET', $env->baseUrl . "/admin/veterinary-applications/view.php?id={$id}", ['cookie_jar' => $jar]);
        $csrf = extract_csrf($view['body']);
        $gen = http_request('POST', $env->baseUrl . "/admin/veterinary-applications/view.php?id={$id}", [
            'cookie_jar' => $jar,
            'body' => ['csrf_token' => $csrf, 'action' => 'generate_activation_link', 'note' => ''],
        ]);
        assert_contains('not currently approved', $gen['body']);
        assert_true(!preg_match('#token=[a-f0-9]+#', $gen['body']), 'no activation link should be generated for a non-approved application');
    });

    run_test('activation is also refused on POST if the application is rejected between loading the page and submitting it', function () use ($env) {
        // Simulates the actual TOCTOU: an applicant loads activate.php while their
        // application is still approved (getting a real page + CSRF token), an admin
        // rejects the application in the meantime, then the applicant submits the form
        // they already had open. The earlier GET-time check alone can't catch this —
        // only re-checking inside the POST's own UPDATE does. This test sets up its own
        // "approved, pending activation, valid token" state directly via SQL — not via
        // generate_activation_link, which now correctly refuses to mint a token here —
        // so this scenario can still be exercised independently of that action.
        $id    = $env->shared['vetApplicationId'];
        $token = bin2hex(random_bytes(32));

        (function () use ($env, $id, $token) {
            $pdo = $env->pdo();
            $pdo->exec("UPDATE vet_applications SET status = 'approved' WHERE id = {$id}");
            $pdo->prepare("UPDATE vet_accounts SET status = 'pending_activation', activation_token_hash = ?, activation_expires_at = ? WHERE vet_application_id = ?")
                ->execute([hash('sha256', $token), gmdate('Y-m-d H:i:s', time() + 3600), $id]);
        })();

        // The applicant "already has this page open" — loads it while still approved.
        $jar = $env->tmpDir . '/cookies-activate-toctou.txt';
        $get = http_request('GET', $env->baseUrl . '/for-veterinarians/activate.php?token=' . $token, ['cookie_jar' => $jar]);
        assert_contains('Set a password', $get['body'], 'the page should render the real activation form while still approved');
        $csrf = extract_csrf($get['body']);

        // An admin rejects it in the meantime, without the applicant's page reloading.
        (function () use ($env, $id) {
            $env->pdo()->exec("UPDATE vet_applications SET status = 'rejected' WHERE id = {$id}");
        })();

        // The applicant submits the form they already had loaded.
        $post = http_request('POST', $env->baseUrl . '/for-veterinarians/activate.php', [
            'cookie_jar' => $jar,
            'body' => ['csrf_token' => $csrf, 'token' => $token, 'password' => 'toctou-test-password-1', 'password_confirm' => 'toctou-test-password-1'],
        ]);
        assert_contains('invalid or has expired', $post['body'], 'the POST must re-check application status itself, not just trust the earlier GET');
        assert_equal('pending_activation', $env->scalar('SELECT status FROM vet_accounts WHERE vet_application_id = ?', [$id]), 'the account must not have been activated');
    });

    run_test('re-approving a rejected, never-activated application resets its account to pending_activation with a fresh token', function () use ($env) {
        // The account from the previous tests is currently 'pending_activation' with a
        // stale token, on an application that's currently 'rejected'. Force it to the exact
        // starting state this test is actually about: account suspended (as a real reject
        // would leave it — see the "rejecting a vet application..." test earlier), never
        // activated (password_hash still NULL, since nothing in this suite ever activates
        // asha.verma's account).
        $id = $env->shared['vetApplicationId'];
        (function () use ($env, $id) {
            $env->pdo()->exec("UPDATE vet_accounts SET status = 'suspended', activation_token_hash = NULL, activation_expires_at = NULL WHERE vet_application_id = {$id}");
        })();
        assert_true($env->scalar('SELECT password_hash FROM vet_accounts WHERE vet_application_id = ?', [$id]) === null, 'sanity check: this account should never have been activated');

        $jar = $env->cookieJarStaff;
        $view = http_request('GET', $env->baseUrl . "/admin/veterinary-applications/view.php?id={$id}", ['cookie_jar' => $jar]);
        $csrf = extract_csrf($view['body']);
        $post = http_request('POST', $env->baseUrl . "/admin/veterinary-applications/view.php?id={$id}", [
            'cookie_jar' => $jar,
            'body' => ['csrf_token' => $csrf, 'action' => 'approve', 'note' => ''],
        ]);
        assert_contains('fresh activation link has been generated', $post['body']);
        assert_equal('pending_activation', $env->scalar('SELECT status FROM vet_accounts WHERE vet_application_id = ?', [$id]), 'a never-activated account must be reset to pending_activation, not left suspended');
        assert_true((bool) preg_match('#https://kuronyx\.in/for-veterinarians/activate\.php\?token=[a-f0-9]+#', $post['body']), 'the compose panel should default to the Approved template with a real, fresh link');
    });
};
