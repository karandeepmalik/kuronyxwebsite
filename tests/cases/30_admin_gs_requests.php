<?php
return function (TestEnv $env): void {
    run_test('admin can list and filter gs-requests', function () use ($env) {
        $r = http_request('GET', $env->baseUrl . '/admin/gs-requests/?source=cat_owner', ['cookie_jar' => $env->cookieJarStaff]);
        assert_equal(200, $r['status']);
        assert_contains('Priya Nair', $r['body']);
    });

    run_test('admin can view a case, change status, add a note, and record final formulation', function () use ($env) {
        $id = $env->shared['gsRequestId'] ?? null;
        assert_true($id !== null, 'expected a gs_request id from the public-forms test');
        $jar = $env->cookieJarStaff;

        $view = http_request('GET', $env->baseUrl . "/admin/gs-requests/view.php?id={$id}", ['cookie_jar' => $jar]);
        assert_equal(200, $view['status']);
        $csrf = extract_csrf($view['body']);
        $lockVersion = $env->scalar('SELECT lock_version FROM gs_requests WHERE id = ?', [$id]);

        $statusPost = http_request('POST', $env->baseUrl . "/admin/gs-requests/view.php?id={$id}", [
            'cookie_jar' => $jar,
            'body' => ['csrf_token' => $csrf, 'action' => 'change_status', 'new_status' => 'under_review', 'status_note' => 'Reviewing prescription', 'expected_lock_version' => $lockVersion],
        ]);
        assert_contains('Status updated', $statusPost['body']);
        assert_equal('under_review', $env->pdo()->query("SELECT status FROM gs_requests WHERE id = {$id}")->fetchColumn());

        $notePost = http_request('POST', $env->baseUrl . "/admin/gs-requests/view.php?id={$id}", [
            'cookie_jar' => $jar,
            'body' => ['csrf_token' => $csrf, 'action' => 'add_note', 'content' => 'Called the clinic to confirm.'],
        ]);
        assert_contains('Note added', $notePost['body']);

        // update_final is optimistically locked on lock_version (see expected_lock_version
        // below) — change_status above already bumped it, so a fresh read is needed here
        // rather than reusing whatever value the original GET at the top of this test saw.
        $expectedLockVersion = $env->scalar('SELECT lock_version FROM gs_requests WHERE id = ?', [$id]);
        $finalPost = http_request('POST', $env->baseUrl . "/admin/gs-requests/view.php?id={$id}", [
            'cookie_jar' => $jar,
            'body' => [
                'csrf_token' => $csrf, 'action' => 'update_final', 'expected_lock_version' => $expectedLockVersion,
                'final_formulation' => 'Oral suspension', 'final_concentration' => '15mg/ml',
                'final_quantity' => '30ml', 'final_price' => '4200', 'courier' => 'BlueDart',
                'tracking_number' => 'BD123', 'dispatch_date' => '', 'closure_reason' => '',
            ],
        ]);
        assert_contains('Case details updated', $finalPost['body']);

        $histStmt = $env->pdo()->prepare('SELECT COUNT(*) FROM case_status_history WHERE gs_request_id = ?');
        $histStmt->execute([$id]);
        assert_true((int) $histStmt->fetchColumn() >= 2, 'status history should include the submission + the manual change');

        $auditStmt = $env->pdo()->prepare("SELECT COUNT(*) FROM audit_log WHERE entity_type = 'gs_request' AND entity_id = ?");
        $auditStmt->execute([$id]);
        assert_true((int) $auditStmt->fetchColumn() >= 3, 'expected status/note/final actions to be audited');
    });

    run_test('submitting update_final or assign_staff with a stale expected_lock_version is rejected, not silently overwritten', function () use ($env) {
        // Regression test for the lost-update fix: these actions are now conditioned on
        // lock_version still matching what the form was loaded with (a plain updated_at
        // comparison was tried first, but its second-level precision means two edits
        // within the same second would look identical and the race wouldn't actually be
        // caught — see db/migrations/2026-10-02_add_gs_requests_lock_version.sql).
        // Simulates the race by using an intentionally stale token (as if another staff
        // member's edit had landed in between) rather than true concurrency, which this
        // sequential test harness can't drive directly — the same technique used
        // elsewhere in this suite for TOCTOU-style checks (see activate.php's test in
        // 70_notifications.php).
        $id = $env->shared['gsRequestId'];
        $jar = $env->cookieJarStaff;
        $view = http_request('GET', $env->baseUrl . "/admin/gs-requests/view.php?id={$id}", ['cookie_jar' => $jar]);
        $csrf = extract_csrf($view['body']);

        $staleLockVersion = $env->scalar('SELECT lock_version FROM gs_requests WHERE id = ?', [$id]);
        $priceBefore = $env->scalar('SELECT final_price FROM gs_requests WHERE id = ?', [$id]);

        // Something else changes the row in between (a different staff member's action,
        // via the app's own update path, which always bumps lock_version by 1).
        $env->pdo()->prepare("UPDATE gs_requests SET courier = 'InterimCourier', lock_version = lock_version + 1 WHERE id = ?")->execute([$id]);

        $stalePost = http_request('POST', $env->baseUrl . "/admin/gs-requests/view.php?id={$id}", [
            'cookie_jar' => $jar,
            'body' => [
                'csrf_token' => $csrf, 'action' => 'update_final', 'expected_lock_version' => $staleLockVersion,
                'final_formulation' => 'Should not be saved', 'final_concentration' => '', 'final_quantity' => '',
                'final_price' => '999', 'courier' => '', 'tracking_number' => '', 'dispatch_date' => '', 'closure_reason' => '',
            ],
        ]);
        assert_contains('changed by someone else', $stalePost['body']);
        assert_equal($priceBefore, $env->scalar('SELECT final_price FROM gs_requests WHERE id = ?', [$id]), 'the stale submission must not have overwritten the stored value');

        // A fresh expected_lock_version (as a reloaded page would send) succeeds normally.
        $freshLockVersion = $env->scalar('SELECT lock_version FROM gs_requests WHERE id = ?', [$id]);
        $csrf2 = extract_csrf($stalePost['body']);
        $freshPost = http_request('POST', $env->baseUrl . "/admin/gs-requests/view.php?id={$id}", [
            'cookie_jar' => $jar,
            'body' => [
                'csrf_token' => $csrf2, 'action' => 'update_final', 'expected_lock_version' => $freshLockVersion,
                'final_formulation' => 'Oral suspension', 'final_concentration' => '15mg/ml',
                'final_quantity' => '30ml', 'final_price' => '4200', 'courier' => 'BlueDart',
                'tracking_number' => 'BD123', 'dispatch_date' => '', 'closure_reason' => '',
            ],
        ]);
        assert_contains('Case details updated', $freshPost['body']);
    });

    run_test('change_status is also optimistically locked and records the true current status as "from", not a stale snapshot', function () use ($env) {
        // Regression test for two change_status bugs: (1) it ignored
        // expected_lock_version entirely, so — like update_final/assign_staff before their
        // own fix — a stale page (or a double-click) could silently overwrite a newer
        // status with no warning; (2) previous_status in case_status_history came from
        // $case, the snapshot read once at the top of the script, not a fresh read inside
        // the transaction, so if the status had actually changed since then, history would
        // record the wrong "from" value. Simulates a concurrent change via raw SQL between
        // this test's GET and its POST (same technique as the update_final test above),
        // and confirms the recorded previous_status matches what the concurrent change
        // actually left it as, not what this request's page load originally saw.
        $id = $env->shared['gsRequestId'];
        $jar = $env->cookieJarStaff;
        $view = http_request('GET', $env->baseUrl . "/admin/gs-requests/view.php?id={$id}", ['cookie_jar' => $jar]);
        $csrf = extract_csrf($view['body']);
        $staleLockVersion = $env->scalar('SELECT lock_version FROM gs_requests WHERE id = ?', [$id]);

        // A stale submission must be rejected outright.
        $stalePost = http_request('POST', $env->baseUrl . "/admin/gs-requests/view.php?id={$id}", [
            'cookie_jar' => $jar,
            'body' => ['csrf_token' => $csrf, 'action' => 'change_status', 'new_status' => 'cancelled', 'status_note' => '', 'expected_lock_version' => $staleLockVersion - 1],
        ]);
        assert_contains('changed by someone else', $stalePost['body']);

        // Something else changes the row in between (a different staff member's action).
        $env->pdo()->prepare("UPDATE gs_requests SET status = 'communication_in_progress', lock_version = lock_version + 1 WHERE id = ?")->execute([$id]);
        $freshLockVersion = $env->scalar('SELECT lock_version FROM gs_requests WHERE id = ?', [$id]);

        $csrf2 = extract_csrf($stalePost['body']);
        $post = http_request('POST', $env->baseUrl . "/admin/gs-requests/view.php?id={$id}", [
            'cookie_jar' => $jar,
            'body' => ['csrf_token' => $csrf2, 'action' => 'change_status', 'new_status' => 'formulation_discussion', 'status_note' => '', 'expected_lock_version' => $freshLockVersion],
        ]);
        assert_contains('Status updated', $post['body']);

        $lastHistory = $env->pdo()->query(
            "SELECT previous_status, new_status FROM case_status_history WHERE gs_request_id = {$id} ORDER BY id DESC LIMIT 1"
        )->fetch();
        assert_equal('communication_in_progress', $lastHistory['previous_status'], 'history must record the true current status, not the page-load-time snapshot, as "from"');
        assert_equal('formulation_discussion', $lastHistory['new_status']);
    });

    run_test('a non-existent case returns 404', function () use ($env) {
        $r = http_request('GET', $env->baseUrl . '/admin/gs-requests/view.php?id=999999', ['cookie_jar' => $env->cookieJarStaff]);
        assert_equal(404, $r['status']);
    });
};
