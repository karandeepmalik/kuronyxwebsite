<?php
// Post/Redirect/Get on admin/gs-requests/view.php (a Karandeep review comment). A successful action used to answer
// the POST with the result page itself, so a browser refresh RE-SENT it: "Add note" has neither a lock version nor a
// one-time token, so F5 or a double-click saved the note twice, and a refresh after a status change re-sent a stale
// lock version and showed a confusing "changed by someone else". Now a success answers with a redirect to a plain GET
// and the message is shown once from the session. Errors and refusals stay on the page so what was typed is kept.
return function (TestEnv $env): void {
    $id  = $env->shared['gsRequestId'];
    $jar = $env->cookieJarStaff;
    $url = $env->baseUrl . "/admin/gs-requests/view.php?id={$id}";

    $page = fn (): string => http_request('GET', $url, ['cookie_jar' => $jar])['body'];
    // The raw POST (no following): exactly what the browser receives straight after clicking the button.
    $post = function (string $action, array $fields = []) use ($env, $id, $jar, $url, $page): array {
        $html = $page();
        $lock = $env->scalar('SELECT lock_version FROM gs_requests WHERE id = ?', [$id]);
        return http_request('POST', $url, ['cookie_jar' => $jar, 'body' => array_merge(
            ['csrf_token' => extract_csrf($html), 'ott' => extract_ott($html), 'action' => $action, 'expected_lock_version' => $lock], $fields
        )]);
    };
    // The later test files expect the shared case as the earlier ones left it: put it back at the end.
    $before = $env->pdo()->query("SELECT status, final_formulation, assigned_staff_id FROM gs_requests WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
    $noteCount = fn (string $text): int => (int) $env->scalar('SELECT COUNT(*) FROM case_internal_notes WHERE gs_request_id = ? AND content = ?', [$id, $text]);
    $audits = fn (string $action): int => (int) $env->scalar('SELECT COUNT(*) FROM audit_log WHERE action = ? AND entity_type = ? AND entity_id = ?', [$action, 'gs_request', $id]);

    run_test('a successful note answers with a redirect to a plain GET of the case, not with the result page', function () use ($post, $id) {
        $r = $post('add_note', ['content' => 'Redirect check: first note.']);
        assert_equal(302, $r['status'], 'a successful POST must redirect');
        assert_contains("/admin/gs-requests/view.php?id={$id}", (string) $r['location']);
        assert_true(strpos($r['body'], 'Note added.') === false, 'the message is not in the POST response itself');
    });

    run_test('Add note: the message shows once after the redirect, and reloading the page adds nothing', function () use ($post, $page, $noteCount, $audits) {
        $text = 'Redirect check: called the clinic.';
        $auditsBefore = $audits('note_added');
        $r = $post('add_note', ['content' => $text]);
        assert_equal(302, $r['status']);
        assert_equal(1, $noteCount($text));
        assert_equal($auditsBefore + 1, $audits('note_added'));

        $afterRedirect = $page();
        assert_contains('Note added.', $afterRedirect, 'the outcome is shown after the redirect');
        assert_contains($text, $afterRedirect, 'the new note is in the list');

        // Refreshing is a GET now: the note is not saved again, and the message is gone.
        $second = $page();
        $third  = $page();
        assert_true(strpos($second, 'Note added.') === false && strpos($third, 'Note added.') === false, 'the message is shown once');
        assert_equal(1, $noteCount($text), 'reloading did not save the note again');
        assert_equal($auditsBefore + 1, $audits('note_added'), 'reloading did not audit the note again');
    });

    run_test('a status change redirects, and a reload afterwards shows no "changed by someone else"', function () use ($post, $page, $env, $id) {
        $versionBefore = (int) $env->scalar('SELECT lock_version FROM gs_requests WHERE id = ?', [$id]);
        $current = (string) $env->scalar('SELECT status FROM gs_requests WHERE id = ?', [$id]);
        $new = $current === 'awaiting_information' ? 'under_review' : 'awaiting_information';
        $r = $post('change_status', ['new_status' => $new, 'status_note' => '']);
        assert_equal(302, $r['status']);
        assert_equal($versionBefore + 1, (int) $env->scalar('SELECT lock_version FROM gs_requests WHERE id = ?', [$id]));

        assert_contains('Status updated', $page());
        $reloaded = $page();
        assert_true(strpos($reloaded, 'changed by someone else') === false, 'a reload is a plain GET: no conflict message');
        assert_true(strpos($reloaded, 'Status updated') === false, 'the message is shown once');
        assert_equal($versionBefore + 1, (int) $env->scalar('SELECT lock_version FROM gs_requests WHERE id = ?', [$id]), 'reloading changed nothing');
    });

    run_test('every other successful action redirects too (case details, assignment, email sent, "no email needed")', function () use ($post, $page, $env, $id) {
        $r = $post('update_final', ['final_formulation' => 'Oral suspension', 'final_concentration' => '', 'final_quantity' => '', 'final_price' => '', 'courier' => '', 'tracking_number' => '', 'dispatch_date' => '', 'closure_reason' => '']);
        assert_equal(302, $r['status'], 'case details');
        assert_contains('Case details updated', $page());

        $staffId = (int) $env->scalar('SELECT id FROM staff_users WHERE active = 1 ORDER BY id LIMIT 1');
        $r = $post('assign_staff', ['assigned_staff_id' => (string) $staffId]);
        assert_equal(302, $r['status'], 'assignment');
        assert_contains('Assignment updated', $page());

        $r = $post('send_email', ['sender' => 'ops@kuronyx.in', 'recipient' => (string) $env->scalar('SELECT owner_email FROM gs_requests WHERE id = ?', [$id]), 'subject' => 'Redirect check', 'body' => 'Just checking in.']);
        assert_equal(302, $r['status'], 'email sent');
        assert_contains('Email sent', $page());

        $r = $post('no_email_needed');
        assert_equal(302, $r['status'], 'no email needed');
        assert_contains('Noted', $page());
    });

    run_test('errors and refusals are NOT redirected: the message and what was typed stay on the page', function () use ($post, $env, $id, $noteCount) {
        $r = $post('add_note', ['content' => '   ']);
        assert_equal(200, $r['status'], 'an empty note is answered on the page itself');
        assert_contains('Note cannot be empty.', $r['body']);

        $long = str_repeat('x', 10001);
        $r = $post('add_note', ['content' => $long]);
        assert_equal(200, $r['status']);
        assert_contains('The note is too long', $r['body']);
        assert_contains(str_repeat('x', 200), $r['body'], 'the over-long note is kept in the box');
        assert_equal(0, $noteCount($long));

        // A stale lock version (another person saved first) is a refusal, not a success.
        $html = http_request('GET', $env->baseUrl . "/admin/gs-requests/view.php?id={$id}", ['cookie_jar' => $env->cookieJarStaff])['body'];
        $r = http_request('POST', $env->baseUrl . "/admin/gs-requests/view.php?id={$id}", ['cookie_jar' => $env->cookieJarStaff, 'body' => [
            'csrf_token' => extract_csrf($html), 'action' => 'change_status', 'new_status' => 'cancelled', 'status_note' => '',
            'expected_lock_version' => (int) $env->scalar('SELECT lock_version FROM gs_requests WHERE id = ?', [$id]) - 1,
        ]]);
        assert_equal(200, $r['status']);
        assert_contains('changed by someone else', $r['body']);
    });

    run_test('a message waiting for one case is not shown on, or used up by, another case', function () use ($env, $id, $jar, $post, $page) {
        $pdo = $env->pdo();
        $pdo->exec("INSERT INTO gs_requests (source, owner_full_name, owner_email, owner_phone, owner_address, owner_city, owner_state, owner_pin, patient_name, requested_formulation, status)
            VALUES ('cat_owner', 'Redirect Other Owner', 'redirect.other@example.test', '9000000001', 'x', 'x', 'x', '000000', 'OtherCat', 'oral', 'submitted')");
        $otherId = (int) $pdo->lastInsertId();
        $otherUrl = $env->baseUrl . "/admin/gs-requests/view.php?id={$otherId}";
        try {
            // Two successes in a row, on two different cases, before either page is read: neither message may be lost.
            $r = $post('add_note', ['content' => 'Redirect check: message for the first case.']);
            assert_equal(302, $r['status']);
            $otherHtml = http_request('GET', $otherUrl, ['cookie_jar' => $jar])['body'];
            assert_true(strpos($otherHtml, 'Note added.') === false, 'the other case does not show the first case\'s message');
            $r = http_request('POST', $otherUrl, ['cookie_jar' => $jar, 'body' => [
                'csrf_token' => extract_csrf($otherHtml), 'action' => 'add_note', 'content' => 'Redirect check: message for the other case.',
            ]]);
            assert_equal(302, $r['status']);
            assert_contains('Note added.', http_request('GET', $otherUrl, ['cookie_jar' => $jar])['body'], 'the other case shows its own message');
            assert_contains('Note added.', $page(), 'the first case still shows its own message, it was not used up or overwritten');
        } finally {
            $pdo->exec("DELETE FROM case_internal_notes WHERE gs_request_id = {$otherId}");
            $pdo->exec("DELETE FROM audit_log WHERE entity_type = 'gs_request' AND entity_id = {$otherId}");
            $pdo->exec("DELETE FROM gs_requests WHERE id = {$otherId}");
        }
    });

    run_test('the success message is read once and removed from the session (static check)', function () use ($env) {
        $src = file_get_contents($env->root . '/public_html/admin/gs-requests/view.php');
        assert_contains("unset(\$_SESSION['case_flash'][\$id])", $src, 'read once, then removed');
        assert_contains("header('Location: /admin/gs-requests/view.php?id=' . \$id)", $src);
    });
    run_test('(housekeeping) the shared case is put back as this file found it', function () use ($env, $id, $before) {
        $env->pdo()->prepare('UPDATE gs_requests SET status = ?, final_formulation = ?, assigned_staff_id = ? WHERE id = ?')
            ->execute([$before['status'], $before['final_formulation'], $before['assigned_staff_id'], $id]);
        assert_equal($before['status'], $env->scalar('SELECT status FROM gs_requests WHERE id = ?', [$id]));
    });
};
