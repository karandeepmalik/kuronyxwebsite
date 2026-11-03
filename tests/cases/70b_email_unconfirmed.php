<?php
// An email whose send got NO answer back (a slow reply past the 15 second limit, a dropped connection) used to be
// recorded as "failed" - but Brevo may have accepted it, so staff clicked Send again and the owner got it twice (a
// Karandeep review comment). There are three outcomes now: sent, failed (we know nothing went out) and unknown (it may
// have been sent: say so, and say how to check before sending again). The harness simulates the two non-sent outcomes
// with two reserved addresses (EMAIL_DRY_RUN_FAIL_TO and EMAIL_DRY_RUN_UNKNOWN_TO, tests/lib/server.php).
return function (TestEnv $env): void {
    $jar = $env->cookieJarStaff;
    $UNKNOWN = 'send-unconfirmed@example.test';
    $FAILS   = 'send-fails@example.test';

    run_test('what a Brevo answer means: only a refusal or a send that never left is "not sent"; no answer after the request went out is "unknown"', function () use ($env) {
        require_once $env->root . '/public_html/includes/mail-outcome.php';
        assert_equal('sent', mail_send_outcome(201, true));
        assert_equal('sent', mail_send_outcome(201, false));
        foreach ([400, 401, 403, 404, 422, 429, 500, 502, 503, 504] as $code) {
            assert_equal('not_sent', mail_send_outcome($code, true), "HTTP {$code}: Brevo answered with an error, so it was refused");
        }
        assert_equal('not_sent', mail_send_outcome(0, false), 'no connection / handshake: the request never left');
        assert_equal('unknown', mail_send_outcome(0, true), 'a timeout or dropped connection AFTER the handshake: it may have been accepted');
        foreach ([200, 202, 204, 301, 302] as $code) {
            assert_equal('unknown', mail_send_outcome($code, true), "HTTP {$code} is not the clear answer we expect, so do not call it a failure");
        }
        $text = mail_unconfirmed_text('owner@example.test');
        assert_contains("couldn't confirm whether this email was sent", $text);
        assert_contains('it may have gone out', $text);
        assert_contains('Brevo', $text);
        assert_contains('owner@example.test', $text, 'it names the address to look for');
        assert_contains('Before sending it again', $text);
    });

    // A case whose owner has the given address (staff may only email addresses on the case).
    $makeCase = function (string $ownerEmail) use ($env): int {
        $pdo = $env->pdo();
        $pdo->prepare(
            "INSERT INTO gs_requests (source, owner_full_name, owner_email, owner_phone, owner_address, owner_city, owner_state, owner_pin, patient_name, requested_formulation, status)
             VALUES ('cat_owner', 'Mail Outcome Owner', ?, '9000000002', 'x', 'x', 'x', '000000', 'MailCat', 'oral', 'submitted')"
        )->execute([$ownerEmail]);
        return (int) $env->scalar('SELECT id FROM gs_requests WHERE owner_email = ? ORDER BY id DESC LIMIT 1', [$ownerEmail]);
    };
    $sendCaseEmail = function (int $id, string $recipient, string $body) use ($env, $jar): array {
        $url  = $env->baseUrl . "/admin/gs-requests/view.php?id={$id}";
        $html = http_request('GET', $url, ['cookie_jar' => $jar])['body'];
        return http_request('POST', $url, ['cookie_jar' => $jar, 'body' => [
            'csrf_token' => extract_csrf($html), 'ott' => extract_ott($html), 'action' => 'send_email',
            'sender' => 'ops@kuronyx.in', 'recipient' => $recipient, 'subject' => 'About your request', 'body' => $body,
        ]]);
    };
    $emailRow = function (int $id) use ($env): array {
        $s = $env->pdo()->prepare('SELECT delivery_status, brevo_message_id FROM case_emails WHERE gs_request_id = ? ORDER BY id DESC LIMIT 1');
        $s->execute([$id]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        $s->closeCursor();
        return $r ?: [];
    };
    $auditCount = fn (string $action, string $type, int $id): int => (int) $env->scalar('SELECT COUNT(*) FROM audit_log WHERE action = ? AND entity_type = ? AND entity_id = ?', [$action, $type, $id]);

    run_test('case page: an unconfirmed send is marked "unknown", says it MAY have gone out and how to check, and keeps the draft', function () use ($env, $jar, $UNKNOWN, $makeCase, $sendCaseEmail, $emailRow, $auditCount) {
        $id = $makeCase($UNKNOWN);
        $r = $sendCaseEmail($id, $UNKNOWN, 'A message whose delivery nobody can confirm.');
        assert_equal(200, $r['status'], 'not redirected: the page keeps the draft');
        $plain = htmlspecialchars_decode($r['body'], ENT_QUOTES); // the page escapes the apostrophe
        assert_contains("couldn't confirm whether this email was sent", $plain);
        assert_contains('it may have gone out', $plain);
        assert_contains('check Brevo', $plain);
        assert_true(strpos($r['body'], 'Email failed to send') === false, 'must NOT say it failed: it may have been sent, and staff would click Send again');
        assert_true(strpos($r['body'], 'Email sent.') === false, 'and must not claim it was sent either');
        // The text also shows in the communication history, so look at the composer's own message box.
        assert_true((bool) preg_match('#<textarea id="email-body" name="body"[^>]*>[^<]*A message whose delivery nobody can confirm\.#', $r['body']), 'the draft is kept in the message box, ready to send once checked');
        assert_equal('unknown', $emailRow($id)['delivery_status'], 'the history row is "unknown", never "failed"');
        assert_equal(1, $auditCount('email_unconfirmed', 'gs_request', $id), 'audited as its own event');
        assert_equal(0, $auditCount('email_failed', 'gs_request', $id));
        assert_equal(0, $auditCount('email_sent', 'gs_request', $id));
        // The communication history says so too, in words and with a neutral (not red, not green) badge.
        $page = http_request('GET', $env->baseUrl . "/admin/gs-requests/view.php?id={$id}", ['cookie_jar' => $jar])['body'];
        assert_contains('Unconfirmed</span>', $page);
        assert_contains('May have been sent: check Brevo', $page);
        assert_true((bool) preg_match('#<span class="status-pill " style="margin-left:0\.5rem;">Unconfirmed</span>#', $page), 'neutral badge: not the green "sent" or the red "failed" one');
    });

    run_test('case page: a send Brevo refused is still "failed" (we know nothing went out), unchanged', function () use ($env, $jar, $FAILS, $makeCase, $sendCaseEmail, $emailRow, $auditCount) {
        $id = $makeCase($FAILS);
        $r = $sendCaseEmail($id, $FAILS, 'A message that is refused.');
        assert_equal(200, $r['status']);
        assert_contains('Email failed to send', $r['body']);
        assert_true(strpos(htmlspecialchars_decode($r['body'], ENT_QUOTES), "couldn't confirm") === false, 'a clear failure is not described as doubtful');
        assert_equal('failed', $emailRow($id)['delivery_status']);
        assert_equal(1, $auditCount('email_failed', 'gs_request', $id));
        assert_equal(0, $auditCount('email_unconfirmed', 'gs_request', $id));
        $page = http_request('GET', $env->baseUrl . "/admin/gs-requests/view.php?id={$id}", ['cookie_jar' => $jar])['body'];
        assert_true(strpos($page, 'Unconfirmed') === false && strpos($page, 'May have been sent') === false);
    });

    run_test('case page: after an unconfirmed send the person can still choose to send again, once they have checked (a new form, a new attempt, its own row)', function () use ($env, $UNKNOWN, $makeCase, $sendCaseEmail) {
        $id = $makeCase($UNKNOWN);
        $sendCaseEmail($id, $UNKNOWN, 'First attempt.');
        $r = $sendCaseEmail($id, $UNKNOWN, 'Second attempt, after checking.');
        assert_equal(200, $r['status']);
        assert_contains("couldn't confirm", htmlspecialchars_decode($r['body'], ENT_QUOTES));
        assert_equal(2, (int) $env->scalar('SELECT COUNT(*) FROM case_emails WHERE gs_request_id = ?', [$id]), 'each attempt is logged');
    });

    // A veterinary application whose professional email is the reserved address.
    $makeApp = function (string $email) use ($env): int {
        $uniq = bin2hex(random_bytes(3));
        $env->pdo()->prepare(
            "INSERT INTO vet_applications (full_name, professional_email, mobile, registration_number, registration_state, qualification, year_qualified,
                 practice_type, clinic_name, clinic_address, clinic_city, clinic_state, clinic_pin, clinic_phone, status)
             VALUES ('Dr Mail Outcome', ?, '9000055555', ?, 'Maharashtra', 'BVSc', 2017, 'Small animal', 'Outcome Clinic', '1 Rd', 'Pune', 'MH', '411001', '02011114444', 'pending')"
        )->execute([$email, "MH-VET-{$uniq}"]);
        return (int) $env->scalar('SELECT id FROM vet_applications WHERE professional_email = ? ORDER BY id DESC LIMIT 1', [$email]);
    };
    $sendAppEmail = function (int $id, string $recipient, string $body) use ($env, $jar): array {
        $url  = $env->baseUrl . "/admin/veterinary-applications/view.php?id={$id}";
        $html = http_request('GET', $url, ['cookie_jar' => $jar])['body'];
        return http_request('POST', $url, ['cookie_jar' => $jar, 'body' => [
            'csrf_token' => extract_csrf($html), 'ott' => extract_ott($html), 'action' => 'send_email',
            'sender' => 'ops@kuronyx.in', 'recipient' => $recipient, 'subject' => 'About your application', 'body' => $body,
        ]]);
    };

    run_test('veterinary application page: an unconfirmed send is noted as NOT CONFIRMED, never "failed", and keeps the draft', function () use ($env, $UNKNOWN, $FAILS, $makeApp, $sendAppEmail, $auditCount) {
        $id = $makeApp($UNKNOWN);
        $r = $sendAppEmail($id, $UNKNOWN, 'An application email nobody can confirm.');
        assert_equal(200, $r['status'], 'not redirected');
        assert_contains("couldn't confirm whether this email was sent", htmlspecialchars_decode($r['body'], ENT_QUOTES));
        assert_true(strpos($r['body'], 'Email failed to send') === false);
        assert_true((bool) preg_match('#<textarea id="email-body" name="body"[^>]*>[^<]*An application email nobody can confirm\.#', $r['body']), 'the draft is kept in the message box');
        $notes = (string) $env->scalar('SELECT internal_notes FROM vet_applications WHERE id = ?', [$id]);
        assert_contains('NOT CONFIRMED', $notes);
        assert_contains('check before resending', $notes);
        assert_true(strpos($notes, 'FAILED') === false);
        assert_equal(1, $auditCount('email_unconfirmed', 'vet_application', $id));
        assert_equal(0, $auditCount('email_failed', 'vet_application', $id));

        // And a refused one stays "failed".
        $id2 = $makeApp($FAILS);
        $r2 = $sendAppEmail($id2, $FAILS, 'An application email that is refused.');
        assert_contains('Email failed to send', $r2['body']);
        assert_contains('FAILED to send', (string) $env->scalar('SELECT internal_notes FROM vet_applications WHERE id = ?', [$id2]));
        assert_equal(1, $auditCount('email_failed', 'vet_application', $id2));
    });

    run_test('staff invitation: an unconfirmed send keeps the account, says it may have gone out, and is audited as unconfirmed', function () use ($env, $jar, $UNKNOWN) {
        $get = http_request('GET', $env->baseUrl . '/admin/staff/new.php', ['cookie_jar' => $jar]);
        $r = http_request('POST', $env->baseUrl . '/admin/staff/new.php', ['cookie_jar' => $jar, 'body' => [
            'csrf_token' => extract_csrf($get['body']), 'ott' => extract_ott($get['body']), 'name' => 'Mail Unsure', 'email' => $UNKNOWN, 'role' => 'pharmacy_staff',
        ]]);
        assert_equal(302, $r['status']);
        $list = http_request('GET', $env->baseUrl . '/admin/staff/', ['cookie_jar' => $jar])['body'];
        assert_contains("we couldn&#039;t confirm the invitation email", $list);
        assert_contains('it may have gone out', $list);
        assert_contains('Forgot your password?', $list);
        assert_true(strpos($list, 'could not be sent') === false, 'not described as a clear failure');
        assert_true(strpos($list, 'We emailed an invitation') === false, 'and not claimed as sent');
        assert_equal(1, (int) $env->scalar('SELECT COUNT(*) FROM staff_users WHERE email = ?', [$UNKNOWN]), 'the account is created');
        $actions = $env->pdo()->prepare("SELECT action FROM audit_log WHERE entity_type = 'staff_user' AND entity_id = (SELECT id FROM staff_users WHERE email = ?) ORDER BY id ASC");
        $actions->execute([$UNKNOWN]);
        assert_equal(['staff_account_created', 'staff_invite_unconfirmed'], $actions->fetchAll(PDO::FETCH_COLUMN));
        $env->pdo()->prepare('DELETE FROM staff_users WHERE email = ?')->execute([$UNKNOWN]);
    });
};
