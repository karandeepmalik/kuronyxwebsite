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

        $statusPost = http_request('POST', $env->baseUrl . "/admin/gs-requests/view.php?id={$id}", [
            'cookie_jar' => $jar,
            'body' => ['csrf_token' => $csrf, 'action' => 'change_status', 'new_status' => 'under_review', 'status_note' => 'Reviewing prescription'],
        ]);
        assert_contains('Status updated', $statusPost['body']);
        assert_equal('under_review', $env->pdo()->query("SELECT status FROM gs_requests WHERE id = {$id}")->fetchColumn());

        $notePost = http_request('POST', $env->baseUrl . "/admin/gs-requests/view.php?id={$id}", [
            'cookie_jar' => $jar,
            'body' => ['csrf_token' => $csrf, 'action' => 'add_note', 'content' => 'Called the clinic to confirm.'],
        ]);
        assert_contains('Note added', $notePost['body']);

        $finalPost = http_request('POST', $env->baseUrl . "/admin/gs-requests/view.php?id={$id}", [
            'cookie_jar' => $jar,
            'body' => [
                'csrf_token' => $csrf, 'action' => 'update_final',
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

    run_test('a non-existent case returns 404', function () use ($env) {
        $r = http_request('GET', $env->baseUrl . '/admin/gs-requests/view.php?id=999999', ['cookie_jar' => $env->cookieJarStaff]);
        assert_equal(404, $r['status']);
    });
};
