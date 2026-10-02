<?php
return function (TestEnv $env): void {
    run_test('session cleanup removes only stale sess_* files and leaves fresh ones alone', function () use ($env) {
        require_once $env->root . '/public_html/includes/session-cleanup.php';
        $dir = $env->tmpDir . '/session-cleanup-test';
        mkdir($dir, 0777, true);
        file_put_contents("{$dir}/sess_old", 'x');
        file_put_contents("{$dir}/sess_fresh", 'x');
        file_put_contents("{$dir}/not-a-session.txt", 'x');
        touch("{$dir}/sess_old", time() - 6 * 3600);
        touch("{$dir}/not-a-session.txt", time() - 6 * 3600);

        $deleted = gs_session_cleanup($dir, 4 * 3600 + 600);

        assert_equal(1, $deleted);
        assert_true(!is_file("{$dir}/sess_old"), 'a session idle past the lifetime should be deleted');
        assert_true(is_file("{$dir}/sess_fresh"), 'a recently used session must be kept');
        assert_true(is_file("{$dir}/not-a-session.txt"), 'only sess_* files are ever touched');
    });

    run_test('consent records are stamped with a fingerprint of the actual privacy policy file, not a hand-typed date', function () use ($env) {
        $expected = 'pp-' . substr(hash_file('sha256', $env->root . '/public_html/Privacy Policy.html'), 0, 16);
        $versions = $env->pdo()->query('SELECT DISTINCT consent_text_version FROM consent_records')->fetchAll(PDO::FETCH_COLUMN);
        assert_true(count($versions) > 0, 'earlier tests submit public forms, so consent rows should exist');
        assert_equal([$expected], $versions, 'every consent should point at the current policy text');
    });

    // Builds a case with every kind of personal data the erasure has to remove, and returns
    // [caseId, filePath, docId]. The PDO handle is throwaway - see the note in
    // 40_admin_vet_applications.php about not holding one open across http_request().
    $makeCase = function (string $status) use ($env): array {
        $pdo = $env->pdo();
        $pdo->prepare(
            "INSERT INTO gs_requests (source, owner_full_name, owner_email, owner_phone, owner_address, owner_city, owner_state, owner_pin,
                 patient_name, patient_breed, clinical_notes, vet_name, vet_email, requested_formulation, status, tracking_number, final_formulation)
             VALUES ('cat_owner','Erase Me','erase.me@example.test','9999999999','1 Test Road','Pune','MH','411001',
                 'Whiskers','Siamese','FIP symptoms','Dr Vet','vet@example.test','oral',?, 'TRK123', 'GS oral 30mg')"
        )->execute([$status]);
        $id = (int) $pdo->lastInsertId();
        $stored = bin2hex(random_bytes(8)) . '.pdf';
        $dir = $env->storageDir . "/gs-requests/{$id}";
        mkdir($dir, 0777, true);
        file_put_contents("{$dir}/{$stored}", '%PDF-1.4 fake');
        $pdo->prepare("INSERT INTO gs_request_documents (gs_request_id, doc_type, stored_filename, original_filename, mime_type, size_bytes) VALUES (?, 'prescription', ?, 'rx.pdf', 'application/pdf', 13)")->execute([$id, $stored]);
        $docId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO consent_records (gs_request_id, consent_type, consent_text_version, ip_address, user_agent) VALUES (?, 'case_processing', 'pp-test', '203.0.113.9', 'UA')")->execute([$id]);
        $pdo->prepare("INSERT INTO case_emails (gs_request_id, recipient, subject, body, delivery_status) VALUES (?, 'erase.me@example.test', 'Hello', 'Body', 'sent')")->execute([$id]);
        $staffId = (int) $pdo->query('SELECT id FROM staff_users ORDER BY id LIMIT 1')->fetchColumn();
        $pdo->prepare('INSERT INTO case_internal_notes (gs_request_id, staff_id, content) VALUES (?, ?, ?)')->execute([$id, $staffId, 'Called owner on 9999999999']);
        $pdo->prepare('INSERT INTO case_status_history (gs_request_id, previous_status, new_status, note) VALUES (?, NULL, ?, ?)')->execute([$id, $status, 'owner said xyz']);
        $pdo->prepare("INSERT INTO audit_log (actor_type, action, entity_type, entity_id, metadata_json) VALUES ('public','case_submitted','gs_request',?,?)")->execute([$id, '{"source_ip":"203.0.113.9"}']);
        $pdo->prepare("INSERT INTO audit_log (actor_type, action, entity_type, entity_id, metadata_json) VALUES ('staff','document_accessed','gs_request_document',?,?)")->execute([$docId, '{"owner_id":1}']);
        return [$id, "{$dir}/{$stored}", $docId];
    };

    $erase = function (int $caseId, string $confirm) use ($env) {
        $jar = $env->cookieJarStaff;
        $get = http_request('GET', $env->baseUrl . '/admin/data-erasure/', ['cookie_jar' => $jar]);
        return http_request('POST', $env->baseUrl . '/admin/data-erasure/', [
            'cookie_jar' => $jar,
            'body' => ['csrf_token' => extract_csrf($get['body']), 'case_id' => $caseId, 'confirm' => $confirm],
        ]);
    };

    run_test('erasing a closed case deletes its files and personal data but keeps the case record', function () use ($env, $makeCase, $erase) {
        [$id, $file, $docId] = $makeCase('closed');
        assert_true(is_file($file));

        $r = $erase($id, 'ERASE');
        assert_contains("GS-{$id} erased", $r['body']);

        clearstatcache();
        assert_true(!is_file($file), 'the uploaded prescription must be deleted from disk');
        $row = $env->pdo()->query("SELECT * FROM gs_requests WHERE id = {$id}")->fetch();
        assert_equal('[erased]', $row['owner_full_name']);
        assert_equal('[erased]', $row['owner_email']);
        assert_equal('[erased]', $row['patient_name']);
        assert_true($row['clinical_notes'] === null && $row['vet_name'] === null && $row['vet_email'] === null && $row['tracking_number'] === null);
        assert_true($row['erased_at'] !== null);
        assert_equal('GS oral 30mg', $row['final_formulation'], 'non-identifying case details are kept');
        foreach (['gs_request_documents', 'consent_records', 'case_emails', 'case_internal_notes'] as $t) {
            assert_equal(0, (int) $env->scalar("SELECT COUNT(*) FROM {$t} WHERE gs_request_id = ?", [$id]), "{$t} should be emptied");
        }
        assert_true($env->scalar('SELECT note FROM case_status_history WHERE gs_request_id = ?', [$id]) === null, 'history notes cleared');
        assert_true($env->scalar("SELECT metadata_json FROM audit_log WHERE action = 'case_submitted' AND entity_id = ?", [$id]) === null, 'IP in the audit metadata cleared');
        assert_true($env->scalar("SELECT metadata_json FROM audit_log WHERE action = 'document_accessed' AND entity_id = ?", [$docId]) === null);
        assert_equal(1, (int) $env->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'case_erased' AND entity_id = ?", [$id]), 'the erasure itself is audited');

        $view = http_request('GET', $env->baseUrl . "/admin/gs-requests/view.php?id={$id}", ['cookie_jar' => $env->cookieJarStaff]);
        assert_contains('Personal data for this case was erased', $view['body']);
        assert_true(strpos($view['body'], 'erase.me@example.test') === false, 'the old email address must not appear anywhere');
    });

    run_test('erasure refuses an open case, a missing confirmation, and a second attempt', function () use ($env, $makeCase, $erase) {
        [$openId, $openFile] = $makeCase('under_review');
        assert_contains('still open', $erase($openId, 'ERASE')['body']);
        assert_true(is_file($openFile), 'an open case must be left untouched');
        assert_true($env->scalar('SELECT erased_at FROM gs_requests WHERE id = ?', [$openId]) === null);

        [$closedId, $closedFile] = $makeCase('cancelled');
        assert_contains('Type ERASE', $erase($closedId, 'erase')['body'], 'the confirmation must be typed exactly');
        assert_true(is_file($closedFile), 'nothing is deleted without the confirmation');

        assert_contains('erased', $erase($closedId, 'ERASE')['body']);
        assert_contains('already been erased', $erase($closedId, 'ERASE')['body']);
        assert_contains('does not exist', $erase(999999, 'ERASE')['body']);
    });

    run_test('an erased case cannot be emailed, and data erasure is admin-only', function () use ($env, $makeCase, $erase) {
        [$id] = $makeCase('rejected');
        $erase($id, 'ERASE');
        $jar = $env->cookieJarStaff;
        $view = http_request('GET', $env->baseUrl . "/admin/gs-requests/view.php?id={$id}", ['cookie_jar' => $jar]);
        $post = http_request('POST', $env->baseUrl . "/admin/gs-requests/view.php?id={$id}", [
            'cookie_jar' => $jar,
            'body' => ['csrf_token' => extract_csrf($view['body']), 'ott' => extract_ott($view['body']), 'action' => 'send_email',
                'sender' => 'ops@kuronyx.in', 'recipient' => '[erased]', 'subject' => 'x', 'body' => 'y'],
        ]);
        assert_contains('has been erased', $post['body']);

        // A fresh pharmacy_staff account with a known password (other tests may have changed or
        // deactivated the shared one by this point).
        $env->pdo()->prepare("INSERT INTO staff_users (name, email, password_hash, role) VALUES ('Erasure Staff', 'erasure.staff@example.test', ?, 'pharmacy_staff')")
            ->execute([password_hash('erasure-staff-password', PASSWORD_DEFAULT)]);
        $staffJar = $env->tmpDir . '/cookies-erasure-nonadmin.txt';
        $get = http_request('GET', $env->baseUrl . '/admin/login.php', ['cookie_jar' => $staffJar]);
        http_request('POST', $env->baseUrl . '/admin/login.php', [
            'cookie_jar' => $staffJar,
            'body' => ['csrf_token' => extract_csrf($get['body']), 'email' => 'erasure.staff@example.test', 'password' => 'erasure-staff-password'],
        ]);
        assert_equal(403, http_request('GET', $env->baseUrl . '/admin/data-erasure/', ['cookie_jar' => $staffJar])['status']);
    });
};
