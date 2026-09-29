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

    run_test('a deactivated staff member cannot keep downloading on a still-live session', function () use ($env) {
        // download.php uses current_staff(), which — unlike require_login() — only reads
        // the session and never re-checks `active` against the DB. Without its own explicit
        // re-check, a deactivated staff member's still-open session would keep working here
        // even though every other admin page would already be booting them out.
        (function () use ($env) {
            $env->pdo()->prepare("INSERT INTO staff_users (name, email, password_hash, role, active) VALUES ('Download Check', 'downloadcheck@example.test', ?, 'pharmacy_staff', 1)")
                ->execute([password_hash('a-download-check-password-1', PASSWORD_DEFAULT)]);
        })();

        $jar = $env->tmpDir . '/cookies-dl-deactivated.txt';
        $get = http_request('GET', $env->baseUrl . '/admin/login.php', ['cookie_jar' => $jar]);
        $csrf = extract_csrf($get['body']);
        http_request('POST', $env->baseUrl . '/admin/login.php', [
            'cookie_jar' => $jar,
            'body' => ['csrf_token' => $csrf, 'email' => 'downloadcheck@example.test', 'password' => 'a-download-check-password-1'],
        ]);

        $docId = (int) $env->pdo()->query('SELECT id FROM gs_request_documents ORDER BY id ASC LIMIT 1')->fetchColumn();
        $before = http_request('GET', $env->baseUrl . "/download.php?kind=gs_request&doc_id={$docId}", ['cookie_jar' => $jar]);
        assert_equal(200, $before['status'], 'session should be valid right after login');

        (function () use ($env) {
            $env->pdo()->exec("UPDATE staff_users SET active = 0 WHERE email = 'downloadcheck@example.test'");
        })();

        $after = http_request('GET', $env->baseUrl . "/download.php?kind=gs_request&doc_id={$docId}", ['cookie_jar' => $jar]);
        assert_equal(302, $after['status'], 'download.php must re-check active status itself, the same as require_login() does elsewhere');
        assert_contains('/admin/login.php', $after['location'] ?? '');
    });
};
