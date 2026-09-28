<?php
return function (TestEnv $env): void {
    run_test('download.php requires authentication', function () use ($env) {
        $docId = (int) $env->pdo()->query('SELECT id FROM gs_request_documents ORDER BY id ASC LIMIT 1')->fetchColumn();
        assert_true($docId > 0, 'expected at least one uploaded document from the public-forms test');
        $r = http_request('GET', $env->baseUrl . "/download.php?kind=gs_request&doc_id={$docId}", ['cookie_jar' => $env->tmpDir . '/cookies-dl-anon.txt']);
        assert_equal(302, $r['status']);
        assert_contains('/admin/login.php', $r['location'] ?? '');
    });

    run_test('an authenticated staff member can download the document and the access is audited', function () use ($env) {
        $docId = (int) $env->pdo()->query('SELECT id FROM gs_request_documents ORDER BY id ASC LIMIT 1')->fetchColumn();
        $r = http_request('GET', $env->baseUrl . "/download.php?kind=gs_request&doc_id={$docId}", ['cookie_jar' => $env->cookieJarStaff]);
        assert_equal(200, $r['status']);
        assert_contains('image/png', $r['headers']);

        $auditStmt = $env->pdo()->prepare("SELECT COUNT(*) FROM audit_log WHERE action = 'document_accessed' AND entity_id = ?");
        $auditStmt->execute([$docId]);
        assert_true((int) $auditStmt->fetchColumn() >= 1, 'document access should be audited');
    });

    run_test('an unknown document id 404s', function () use ($env) {
        $r = http_request('GET', $env->baseUrl . '/download.php?kind=gs_request&doc_id=999999', ['cookie_jar' => $env->cookieJarStaff]);
        assert_equal(404, $r['status']);
    });
};
