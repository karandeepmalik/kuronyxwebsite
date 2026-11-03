<?php
// Approve / reject / suspend / request-info on a veterinary application (admin/veterinary-applications/view.php).
// Two things used to be missing: (1) acting on an out-of-date page quietly undid someone else's decision (open an
// application as Pending, have it rejected elsewhere, click Approve from the old tab and it approved, with no
// warning and no sight of the rejection), and (2) there were no "from which status" rules, so a never-approved
// application could be suspended and an approved one "approved" again. Now each form sends the status it was loaded
// with and the server refuses if it has changed; and the actions follow VET_APPLICATION_ACTIONS (case-statuses.php).
return function (TestEnv $env): void {
    $jar = $env->cookieJarStaff;

    $makeApp = function (string $status) use ($env): int {
        $pdo = $env->pdo();
        $uniq = bin2hex(random_bytes(3));
        $pdo->prepare(
            "INSERT INTO vet_applications (full_name, professional_email, mobile, registration_number, registration_state, qualification, year_qualified,
                 practice_type, clinic_name, clinic_address, clinic_city, clinic_state, clinic_pin, clinic_phone, status)
             VALUES ('Dr Rules Test', ?, '9000033333', ?, 'Maharashtra', 'BVSc', 2016, 'Small animal', 'Rules Clinic', '1 Rd', 'Pune', 'MH', '411001', '02011112222', ?)"
        )->execute(["rules.{$uniq}@example.test", "MH-VET-{$uniq}", $status]); // (no SQL string concatenation: || means OR on MariaDB)
        return (int) $env->scalar('SELECT id FROM vet_applications WHERE professional_email = ?', ["rules.{$uniq}@example.test"]);
    };
    $page = fn (int $id): string => http_request('GET', $env->baseUrl . "/admin/veterinary-applications/view.php?id={$id}", ['cookie_jar' => $jar])['body'];
    // Posts an action the way the browser does: csrf and expected_status come from the page as it is NOW,
    // unless $expected is given (an out-of-date tab, or a crafted request with none).
    $act = function (int $id, string $action, ?string $expected = null, bool $omitExpected = false, string $note = 'Checked in test.') use ($env, $jar, $page): string {
        $html = $page($id);
        $body = ['csrf_token' => extract_csrf($html), 'action' => $action, 'note' => $note];
        if (!$omitExpected) $body['expected_status'] = $expected ?? extract_expected_status($html);
        return http_request('POST', $env->baseUrl . "/admin/veterinary-applications/view.php?id={$id}", ['follow' => true, 'cookie_jar' => $jar, 'body' => $body])['body'];
    };
    $status = fn (int $id): string => (string) $env->scalar('SELECT status FROM vet_applications WHERE id = ?', [$id]);
    $audits = fn (int $id, string $action): int => (int) $env->scalar("SELECT COUNT(*) FROM audit_log WHERE entity_type = 'vet_application' AND entity_id = ? AND action = ?", [$id, $action]);
    $accounts = fn (int $id): int => (int) $env->scalar('SELECT COUNT(*) FROM vet_accounts WHERE vet_application_id = ?', [$id]);
    $notes = fn (int $id): string => (string) $env->scalar('SELECT internal_notes FROM vet_applications WHERE id = ?', [$id]);

    run_test('a stale tab cannot undo someone else\'s decision: Approve after another reviewer rejected is refused and writes nothing', function () use ($makeApp, $page, $act, $status, $audits, $accounts, $notes, $env, $jar) {
        $id = $makeApp('pending');
        $stale = extract_expected_status($page($id));   // this tab was opened while the application was Pending
        assert_equal('pending', $stale);
        assert_contains('Application rejected', $act($id, 'reject')); // another reviewer rejects it meanwhile
        $notesBefore = $notes($id);

        $html = $act($id, 'approve', $stale);              // ...and the old tab clicks Approve
        assert_contains('This application is now Rejected (it was Pending when you opened this page), so nothing was changed.', $html);
        assert_equal('rejected', $status($id), 'the rejection stands');
        assert_equal(0, $accounts($id), 'no portal account was provisioned');
        assert_equal(0, $audits($id, 'application_approved'), 'and no approval was recorded');
        assert_equal($notesBefore, $notes($id), 'and no note was appended');
        // The page now shows the real state.
        assert_true((bool) preg_match('#class="status-pill status-rejected">Rejected<#', $html));
    });

    run_test('a double-click approves once: the second click finds it already approved', function () use ($makeApp, $page, $act, $status, $audits, $accounts) {
        $id = $makeApp('pending');
        $stale = extract_expected_status($page($id));
        assert_contains('Application approved', $act($id, 'approve', $stale));
        $second = $act($id, 'approve', $stale);
        assert_contains('This application is now Approved (it was Pending when you opened this page)', $second);
        assert_equal('approved', $status($id));
        assert_equal(1, $accounts($id), 'one portal account');
        assert_equal(1, $audits($id, 'application_approved'), 'one approval recorded');
    });

    run_test('a request with no expected status, or a made-up one, is refused as out of date', function () use ($makeApp, $act, $status, $accounts) {
        $id = $makeApp('pending');
        assert_contains('This page is out of date, so nothing was changed', $act($id, 'approve', null, true));
        assert_contains('This page is out of date, so nothing was changed', $act($id, 'approve', 'banana'));
        assert_contains('This page is out of date, so nothing was changed', $act($id, 'reject', ''));
        assert_equal('pending', $status($id));
        assert_equal(0, $accounts($id));
    });

    // Every action from every status. Allowed moves reach the new status; refused ones change nothing and say why.
    run_test('the status rules: each action works only from the statuses it makes sense from', function () use ($makeApp, $act, $status, $accounts) {
        $allowed = [
            'approve'      => ['from' => ['pending', 'under_review', 'rejected', 'suspended'], 'to' => 'approved'],
            'reject'       => ['from' => ['pending', 'under_review', 'approved', 'suspended'], 'to' => 'rejected'],
            'suspend'      => ['from' => ['approved'],                                         'to' => 'suspended'],
            'request_info' => ['from' => ['pending'],                                         'to' => 'under_review'],
        ];
        $refusal = [
            'approve' => 'This application is already Approved.', 'reject' => 'This application is already Rejected.',
            'suspend' => 'Only an approved application can be suspended.', 'request_info' => 'Cannot request more information',
        ];
        // (asking for more information on one already under review is a no-op, so it has its own wording)
        $refusalFrom = ['request_info:under_review' => 'This application is already Under Review.'];
        foreach (['pending', 'under_review', 'approved', 'rejected', 'suspended'] as $from) {
            foreach ($allowed as $action => $rule) {
                $id = $makeApp($from);
                $html = $act($id, $action);
                if (in_array($from, $rule['from'], true)) {
                    assert_equal($rule['to'], $status($id), "{$action} from {$from} must move to {$rule['to']}");
                    assert_true(strpos($html, '<div class="alert ">') === false, "{$action} from {$from} must not be refused (no error alert)");
                    assert_true(strpos($html, 'class="alert alert-ok"') !== false, "{$action} from {$from} shows a success message");
                } else {
                    assert_equal($from, $status($id), "{$action} from {$from} must be refused and change nothing");
                    assert_contains($refusalFrom["{$action}:{$from}"] ?? $refusal[$action], $html, "{$action} from {$from}: the reason");
                }
            }
        }
        // The case Karandeep named: a never-approved application cannot be suspended.
        $id = $makeApp('pending');
        assert_contains('Only an approved application can be suspended. This one is Pending.', $act($id, 'suspend'));
        assert_equal('pending', $status($id));
        assert_equal(0, $accounts($id), 'and no account was touched');
    });

    run_test('reversals are still allowed: an approved application can be rejected (locking the account) and re-approved', function () use ($makeApp, $act, $status, $env) {
        $id = $makeApp('pending');
        assert_contains('Application approved', $act($id, 'approve'));
        assert_equal('pending_activation', $env->scalar('SELECT status FROM vet_accounts WHERE vet_application_id = ?', [$id]));
        assert_contains('Application rejected', $act($id, 'reject'));
        assert_equal('suspended', $env->scalar('SELECT status FROM vet_accounts WHERE vet_application_id = ?', [$id]), 'rejecting locks the portal account');
        assert_contains('Application approved', $act($id, 'approve'));
        assert_equal('approved', $status($id));
        assert_equal('pending_activation', $env->scalar('SELECT status FROM vet_accounts WHERE vet_application_id = ?', [$id]), 're-approving restores the never-activated account');
    });

    run_test('the page offers only the actions that apply to the current status, and carries that status in the form', function () use ($makeApp, $page) {
        $expect = [
            'pending'      => ['approve', 'reject', 'request_info'],
            'under_review' => ['approve', 'reject'],
            'approved'     => ['reject', 'suspend'],
            'rejected'     => ['approve'],
            'suspended'    => ['approve', 'reject'],
        ];
        foreach ($expect as $status => $buttons) {
            $html = $page($makeApp($status));
            assert_equal($status, extract_expected_status($html), "the form for a {$status} application carries it");
            foreach (['approve', 'reject', 'request_info', 'suspend'] as $action) {
                $present = strpos($html, 'name="action" value="' . $action . '"') !== false;
                assert_equal(in_array($action, $buttons, true), $present, "{$status}: the {$action} button should " . (in_array($action, $buttons, true) ? '' : 'not ') . 'be there');
            }
            assert_contains('name="action" value="add_note"', $html, "{$status}: adding a note is always possible");
        }
    });

    // ---- A recorded reason for the decisions that change who may send us prescriptions ----
    // The checklist tells staff to write what they checked; Approve with an empty box used to just log "Approved.".
    $noteBox = fn (string $html): ?string => preg_match('#<textarea name="note"[^>]*>(.*?)</textarea>#s', $html, $m) ? html_entity_decode($m[1], ENT_QUOTES) : null;

    run_test('approve, reject and suspend need a note: an empty or blank one is refused and nothing is written; with a note they work and record it', function () use ($makeApp, $act, $status, $accounts, $audits, $notes) {
        $cases = [
            ['approve', 'pending',  'approved',  'Write what you checked before approving',        'Required: write what you checked.'],
            ['reject',  'pending',  'rejected',  'Write why you are rejecting this application',   'Required: write why you are rejecting.'],
            ['suspend', 'approved', 'suspended', 'Write why you are suspending this account',      'Required: write why you are suspending.'],
        ];
        foreach ($cases as [$action, $from, $to, $message, $inline]) {
            $id = $makeApp($from);
            $notesBefore = $notes($id);
            foreach (['', "   \n  \t "] as $blank) {
                $html = $act($id, $action, null, false, $blank);
                assert_contains($message, $html, "{$action} with a blank note must say what to write");
                assert_contains('Nothing was changed.', $html);
                // The banner is at the top of a long page and the note box is far below it, so the box says it too:
                // outlined, with the short message under it, and focused so the browser scrolls to it.
                assert_field_error($html, 'note', $inline, "{$action}");
                assert_true((bool) preg_match('#<label class="field has-error">\s*<span class="lbl">Note \(recorded#', $html), "{$action}: the note box is highlighted");
                assert_true((bool) preg_match('#<textarea name="note"[^>]*\sautofocus#', $html), "{$action}: the note box is focused");
                assert_equal($from, $status($id), "{$action} with a blank note must not change the status");
                assert_equal($notesBefore, $notes($id), "{$action}: no note written");
                assert_equal(0, $accounts($id), "{$action}: no account touched");
                assert_equal(0, $audits($id, 'application_approved') + $audits($id, 'application_rejected'), "{$action}: nothing audited");
            }
            $html = $act($id, $action, null, false, 'Certificate checked against the register; clinic called and confirmed.');
            assert_equal($to, $status($id), "{$action} with a note works");
            assert_contains('Certificate checked against the register; clinic called and confirmed.', $notes($id), 'and the reason is recorded in the review history');
        }
    });

    run_test('asking for more information needs no note, and the stale-page message wins over the missing-note message', function () use ($makeApp, $page, $act, $status) {
        $id = $makeApp('pending');
        assert_contains('Marked as needing more information', $act($id, 'request_info', null, false, ''));
        assert_equal('under_review', $status($id));

        // A stale page with an empty note: say the page is out of date (the more important fact), not "write a note".
        $other = $makeApp('pending');
        $stale = extract_expected_status($page($other));
        $act($other, 'reject');
        $html = $act($other, 'approve', $stale, false, '');
        assert_contains('This application is now Rejected (it was Pending when you opened this page)', $html);
        assert_true(strpos($html, 'Write what you checked before approving') === false, 'the missing-note error must not be the one shown');
    });

    run_test('a note that is refused (stale page, status rule) stays in the box so it is not lost; a successful action empties it', function () use ($makeApp, $page, $act, $noteBox) {
        $id = $makeApp('pending');
        $stale = extract_expected_status($page($id));
        $act($id, 'reject');
        $long = "Certificate MH-VET-1 checked against the MSVC register.\nCalled the clinic; confirmed.";
        $html = $act($id, 'approve', $stale, false, $long);
        assert_contains('nothing was changed', $html);
        assert_equal($long, $noteBox($html), 'the note the reviewer wrote comes back');

        $ok = $makeApp('pending');
        $done = $act($ok, 'approve', null, false, 'Verified.');
        assert_contains('Application approved', $done);
        assert_equal('', $noteBox($done), 'after a successful action the box is empty again');
    });

    run_test('the note box says when it is required and shows an example', function () use ($makeApp, $page) {
        $html = $page($makeApp('pending'));
        assert_no_field_error($html, 'note', 'a normal page load');
        assert_true(strpos($html, 'autofocus') === false, 'a normal page load does not steal focus');
        assert_contains('Required for Approve, Reject and Suspend.', $html);
        assert_contains('placeholder="e.g. Certificate MH-VET-1234 checked against the MSVC register', $html);
    });

    // The status UPDATE inside each transaction is conditional on the status that was checked (so two requests that
    // pass the pre-check at the same moment cannot both win), and a lost race is reported, not applied. The test
    // server handles one request at a time, so this part is checked in the source.
    run_test('each status change is a conditional UPDATE inside its transaction, and a lost race is reported', function () use ($env) {
        $src = file_get_contents($env->root . '/public_html/admin/veterinary-applications/view.php');
        assert_equal(4, substr_count($src, "WHERE id = ? AND status = ?'"), 'approve, reject, suspend and request-info each update only if the status is still the one checked');
        assert_equal(4, substr_count($src, "throw new DomainException('status changed')"), 'and each notices when that matched nothing');
        assert_equal(4, substr_count($src, "catch (DomainException \$e)"), 'and each rolls back and reports it');
        assert_contains('$flash = $statusRaced;', $src);
    });
};
