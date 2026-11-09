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
        // Only the privacy consents point at the policy text; a vet's attestation has its own wording version (80_vet_portal.php).
        $versions = $env->pdo()->query("SELECT DISTINCT consent_text_version FROM consent_records WHERE consent_type = 'case_processing'")->fetchAll(PDO::FETCH_COLUMN);
        assert_true(count($versions) > 0, 'earlier tests submit public forms, so consent rows should exist');
        assert_equal([$expected], $versions, 'every privacy consent should point at the current policy text');
        $attestations = $env->pdo()->query("SELECT DISTINCT consent_text_version FROM consent_records WHERE consent_type = 'vet_attestation'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($attestations as $v) assert_true((bool) preg_match('/^att-[0-9a-f]{16}$/', $v) && $v !== $expected, 'an attestation names its wording, not the policy');
    });

    // Builds a case with every kind of personal data the erasure has to remove, and returns
    // [caseId, filePath, docId]. The PDO handle is throwaway - see the note in
    // 40_admin_vet_applications.php about not holding one open across http_request().
    // $updatedAt / $closedAt let a test place a case on the "past retention" review list. updatedAt is
    // gs_requests.updated_at (set on INSERT because SQLite's trigger would reset it on any later
    // UPDATE); closedAt is when the case reached its status, i.e. its case_status_history row - the
    // clock the review list actually uses. closedAt defaults to updatedAt, then to now.
    $makeCase = function (string $status, ?string $updatedAt = null, ?string $closedAt = null) use ($env): array {
        $pdo = $env->pdo();
        $pdo->prepare(
            "INSERT INTO gs_requests (source, owner_full_name, owner_email, owner_phone, owner_address, owner_city, owner_state, owner_pin,
                 patient_name, patient_breed, clinical_notes, vet_name, vet_email, requested_formulation, status, tracking_number, final_formulation, updated_at)
             VALUES ('cat_owner','Erase Me','erase.me@example.test','9999999999','1 Test Road','Pune','MH','411001',
                 'Whiskers','Siamese','FIP symptoms','Dr Vet','vet@example.test','oral',?, 'TRK123', 'GS oral 30mg', ?)"
        )->execute([$status, $updatedAt ?? gmdate('Y-m-d H:i:s')]);
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
        $pdo->prepare('INSERT INTO case_status_history (gs_request_id, previous_status, new_status, note, created_at) VALUES (?, NULL, ?, ?, ?)')->execute([$id, $status, 'owner said xyz', $closedAt ?? $updatedAt ?? gmdate('Y-m-d H:i:s')]);
        $pdo->prepare("INSERT INTO audit_log (actor_type, action, entity_type, entity_id, metadata_json) VALUES ('public','case_submitted','gs_request',?,?)")->execute([$id, '{"source_ip":"203.0.113.9"}']);
        $pdo->prepare("INSERT INTO audit_log (actor_type, action, entity_type, entity_id, metadata_json) VALUES ('staff','document_accessed','gs_request_document',?,?)")->execute([$docId, '{"owner_id":1}']);
        return [$id, "{$dir}/{$stored}", $docId];
    };

    // The page answers a POST with a redirect (Post/Redirect/Get) and shows the outcome on the next
    // GET. These helpers follow it, so tests can keep asserting on the message the way a person sees
    // it; the redirect response itself is in $result['post'].
    $postAndFollow = function (array $body) use ($env) {
        $jar = $env->cookieJarStaff;
        $get = http_request('GET', $env->baseUrl . '/admin/data-erasure/', ['cookie_jar' => $jar]);
        $post = http_request('POST', $env->baseUrl . '/admin/data-erasure/', [
            'cookie_jar' => $jar,
            'body' => ['csrf_token' => extract_csrf($get['body'])] + $body,
        ]);
        if ($post['status'] !== 302) return $post + ['post' => $post];
        $followed = http_request('GET', $env->baseUrl . $post['location'], ['cookie_jar' => $jar]);
        return $followed + ['post' => $post];
    };

    $erase = function (int $caseId, string $confirm) use ($postAndFollow) {
        return $postAndFollow(['case_id' => $caseId, 'confirm' => $confirm]);
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

    // What the per-row Erase dialog posts: the number typed into the dialog (case_id), the word
    // typed into the dialog (confirm), and expected_case_id - the row that was clicked.
    $eraseViaDialog = function (int $expected, string $typedCaseId, string $confirm) use ($postAndFollow) {
        return $postAndFollow(['expected_case_id' => $expected, 'case_id' => $typedCaseId, 'confirm' => $confirm]);
    };

    // The page's look lives in the shared stylesheet (layout-header.php): the red is one variable and
    // the button/hint variants are classes, not repeated inline style="" attributes.
    run_test('the data-erasure page uses shared classes and the --err colour variable, not inline styles or a hard-coded red', function () use ($env) {
        $page   = file_get_contents($env->root . '/public_html/admin/data-erasure/index.php');
        $header = file_get_contents($env->root . '/public_html/includes/layout-header.php');

        assert_true(strpos($page, 'style="') === false, 'no inline style="" attributes on the page');
        assert_true(stripos($page, '#ff8787') === false, 'the page must use var(--err), not the literal colour');
        assert_true(stripos(preg_replace('/--err:#ff8787;/i', '', $header), '#ff8787') === false, 'the stylesheet may spell the red only once, where --err is defined');
        assert_contains('--err:#ff8787;', $header);
        foreach (['.btn-secondary.btn-danger', '.btn-secondary.btn-sm', '.field-hint.spaced'] as $selector) {
            assert_contains($selector, $header, "{$selector} must be defined in the shared stylesheet");
        }
        foreach (['btn-secondary btn-danger', 'btn-secondary btn-sm', 'field-hint spaced'] as $class) {
            assert_contains($class, $page, "the page should use class \"{$class}\"");
        }

        // And the rendered page still carries those classes on the real controls.
        $html = http_request('GET', $env->baseUrl . '/admin/data-erasure/', ['cookie_jar' => $env->cookieJarStaff])['body'];
        assert_true((bool) preg_match('/<button[^>]*class="btn-secondary btn-danger"[^>]*>Erase Case Data/', $html), 'the top form button is a danger button');
        assert_true((bool) preg_match('/<button[^>]*class="btn-secondary btn-danger"[^>]*id="erase-dialog-submit"/', $html), 'the dialog button is a danger button');
    });

    // The review list measures retention from when a case reached its final status (its newest status
    // history row for the status it is in now), not from updated_at, which any later edit resets.
    run_test('the retention review list counts from when the case closed, so later edits do not reset its clock', function () use ($env, $makeCase) {
        $old = '2000-01-01 00:00:00';
        $now = gmdate('Y-m-d H:i:s');
        $listed = function () use ($env): array {
            $page = http_request('GET', $env->baseUrl . '/admin/data-erasure/', ['cookie_jar' => $env->cookieJarStaff])['body'];
            preg_match_all('/data-erase-case="(\d+)"/', $page, $m);
            return array_map('intval', $m[1]);
        };

        // Closed long ago, then edited today (updated_at = now, as after fixing a price): still listed.
        [$editedAfterClose] = $makeCase('closed', $now, $old);
        // Closed long ago and untouched: listed.
        [$untouched] = $makeCase('closed', $old, $old);
        // Closed just now: not past retention, even though its row was last updated long ago.
        [$closedRecently] = $makeCase('finished', $old, $now);
        // Closed long ago, reopened, closed again just now: the clock restarts at the later close.
        [$reclosed] = $makeCase('closed', $old, $old);
        $env->pdo()->prepare("INSERT INTO case_status_history (gs_request_id, previous_status, new_status, created_at) VALUES (?, 'closed', 'under_review', ?)")->execute([$reclosed, '2000-06-01 00:00:00']);
        $env->pdo()->prepare("INSERT INTO case_status_history (gs_request_id, previous_status, new_status, created_at) VALUES (?, 'under_review', 'closed', ?)")->execute([$reclosed, $now]);
        // Not a final status: never listed however old.
        [$open] = $makeCase('under_review', $old, $old);
        // No history rows at all (older data): falls back to updated_at instead of disappearing.
        [$noHistory] = $makeCase('cancelled', $old, $old);
        $env->pdo()->prepare('DELETE FROM case_status_history WHERE gs_request_id = ?')->execute([$noHistory]);

        $ids = $listed();
        assert_true(in_array($editedAfterClose, $ids, true), 'a long-closed case that was edited since must stay on the list');
        assert_true(in_array($untouched, $ids, true), 'a long-closed untouched case is listed');
        assert_true(in_array($noHistory, $ids, true), 'a case with no history falls back to updated_at');
        assert_true(!in_array($closedRecently, $ids, true), 'a case closed just now is not past retention');
        assert_true(!in_array($reclosed, $ids, true), 'a case closed again recently restarts its clock');
        assert_true(!in_array($open, $ids, true), 'an open case is never listed');

        // The page now shows the closing date, not "last updated".
        $page = http_request('GET', $env->baseUrl . '/admin/data-erasure/', ['cookie_jar' => $env->cookieJarStaff])['body'];
        assert_contains('<th>Closed</th>', $page);
        // ... and says what happens to the audit log, so staff aren't surprised the details are gone.
        assert_contains('The audit log keeps what happened and when, but not the details', $page);
        assert_true(strpos($page, '<th>Last updated</th>') === false, 'the column is the closing date now');
    });

    run_test('the erase form redirects after POST and shows its message once: refreshing never re-sends it', function () use ($env, $makeCase, $erase) {
        [$id] = $makeCase('closed');
        $jar = $env->cookieJarStaff;

        $r = $erase($id, 'ERASE');
        // The POST itself answers with a redirect to a plain GET of the page - no result page, no body to re-send.
        assert_equal(302, $r['post']['status'], 'a POST must be answered with a redirect');
        assert_equal('/admin/data-erasure/', $r['post']['location']);
        assert_true(strpos($r['post']['body'], "GS-{$id} erased") === false, 'the POST response must not itself carry the result page');

        // That GET showed the message ...
        assert_equal(200, $r['status']);
        assert_contains("Case GS-{$id} erased", $r['body']);

        // ... once. Refreshing it (another plain GET) shows no message and no leftover state.
        $again = http_request('GET', $env->baseUrl . '/admin/data-erasure/', ['cookie_jar' => $jar]);
        assert_equal(200, $again['status']);
        assert_true(strpos($again['body'], "GS-{$id} erased") === false, 'the message must be cleared after being shown once');
        assert_true(!preg_match('/class="alert[^"]*">[^<]+</', $again['body']), 'no flash banner on a plain refresh');

        // A failed attempt also goes through the redirect and is shown once.
        $bad = $erase($id, 'nope');
        assert_equal(302, $bad['post']['status']);
        assert_contains('Type ERASE', $bad['body']);
        $cleared = http_request('GET', $env->baseUrl . '/admin/data-erasure/', ['cookie_jar' => $jar]);
        assert_true(strpos($cleared['body'], 'Type ERASE (in capitals)') === false);
    });

    run_test('the row Erase buttons open a dialog instead of posting a pre-filled form: no hidden ERASE, no confirm() popup', function () use ($env, $makeCase) {
        [$id] = $makeCase('closed', '2000-01-01 00:00:00');
        $page = http_request('GET', $env->baseUrl . '/admin/data-erasure/', ['cookie_jar' => $env->cookieJarStaff])['body'];

        assert_contains("data-erase-case=\"{$id}\"", $page, 'the case past retention should have an Erase button');
        assert_contains('<dialog id="erase-dialog"', $page);
        assert_true(strpos($page, 'confirm(') === false, 'the one-click browser confirm() must be gone');
        // The only `confirm` input anywhere is a typed one: nothing is posted pre-filled with ERASE.
        assert_true(!preg_match('/<input[^>]*name="confirm"[^>]*value="ERASE"/', $page), 'ERASE must never be pre-filled');
        assert_true(!preg_match('/<input[^>]*type="hidden"[^>]*name="case_id"/', $page), 'the case number must be typed, not a hidden field');
        // The dialog's two typed fields start empty, and its submit button starts disabled.
        assert_true((bool) preg_match('/<input[^>]*name="case_id"[^>]*id="erase-dialog-case-id"[^>]*required/', $page));
        assert_true((bool) preg_match('/<button[^>]*id="erase-dialog-submit"[^>]*disabled/', $page), 'submit stays disabled until both fields match');
        // The dialog's own check ignores stray spaces around ERASE (like the server) but stays case-sensitive.
        assert_contains("confirmInput.value.trim() === 'ERASE'", $page);
    });

    run_test('the dialog\'s server-side check: the typed number must be the clicked row, and ERASE must be typed', function () use ($env, $makeCase, $eraseViaDialog, $erase) {
        [$a, $fileA] = $makeCase('closed');
        [$b, $fileB] = $makeCase('cancelled');

        // Typed the wrong case's number: refused, and neither case is touched.
        $r = $eraseViaDialog($a, (string) $b, 'ERASE');
        assert_contains('does not match', $r['body']);
        assert_true(is_file($fileA) && is_file($fileB), 'a mismatched number must erase nothing');
        assert_equal(0, (int) $env->scalar('SELECT COUNT(*) FROM gs_requests WHERE id IN (?, ?) AND erased_at IS NOT NULL', [$a, $b]), 'neither case may be marked erased');

        // Same number with trailing junk is not the same number.
        assert_contains('does not match', $eraseViaDialog($a, "{$a}abc", 'ERASE')['body']);
        assert_true(is_file($fileA));

        // Right number but ERASE wrong or missing.
        assert_contains('Type ERASE', $eraseViaDialog($a, (string) $a, 'erase')['body']);
        assert_contains('Type ERASE', $eraseViaDialog($a, (string) $a, '')['body']);
        assert_true(is_file($fileA), 'nothing is erased without the typed word');

        // Wrong case stays refused, with or without stray spaces around the word.
        assert_contains('Type ERASE', $eraseViaDialog($a, (string) $a, ' erase ')['body']);
        assert_true(is_file($fileA));

        // Both typed correctly (surrounding spaces from copy-paste are fine, on the number and on ERASE):
        // erased - and only that case.
        assert_contains("GS-{$a} erased", $eraseViaDialog($a, " {$a} ", ' ERASE ')['body']);
        clearstatcache();
        assert_true(!is_file($fileA), 'the confirmed case is erased');
        assert_true(is_file($fileB), 'the other case is untouched');

        // The form at the top has the same rule: "ERASE " works, lowercase doesn't.
        assert_contains('Type ERASE', $erase($b, 'Erase ')['body']);
        assert_true(is_file($fileB), 'a wrong-case word still erases nothing');
        assert_contains("GS-{$b} erased", $erase($b, "ERASE ")['body']);
        clearstatcache();
        assert_true(!is_file($fileB), 'a trailing space after ERASE is accepted on the top form too');
    });

    // ---- Files that erasure could not delete, or never got to delete ---------------------------------
    // The DB part of an erasure commits first and the files go afterwards, so a delete can fail or the
    // process can die in between - by then the rows that named the files are gone. The stored names are
    // therefore written into the case_erased audit entry inside the transaction, and the page lists
    // whatever of that is still on disk and can retry. See erase_gs_request() / erasure_leftover_files().

    // Runs erase_gs_request ('erase') or delete_erasure_leftovers ('leftovers') in a separate process
    // with a deleter that fails for $failNames (tests/lib/erasure_worker.php), returns its JSON result.
    $runWorker = function (string $task, int $caseId, string $failNames = '') use ($env): array {
        $pipes = [];
        $proc = proc_open(
            ['php', '-c', $env->iniPath, $env->root . '/tests/lib/erasure_worker.php', $task, (string) $caseId, $failNames],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        fclose($pipes[0]);
        // The result is on stdout. stderr carries the app's own error_log() line for the failed
        // delete ("[data-erasure] Could not delete ...") - expected, so kept apart from the JSON.
        $text = trim(stream_get_contents($pipes[1]));
        $log  = trim(stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        $data = json_decode($text, true);
        assert_true(is_array($data), "the erasure worker did not return JSON: {$text} {$log}");
        if ($failNames !== '' && $task === 'erase') {
            assert_contains('[data-erasure] Could not delete', $log, 'a failed delete must also still be written to the error log');
        }
        return $data;
    };
    // A second uploaded document on a case made by $makeCase; returns [storedName, path].
    $addDocument = function (int $caseId) use ($env): array {
        $stored = bin2hex(random_bytes(8)) . '.pdf';
        $path = $env->storageDir . "/gs-requests/{$caseId}/{$stored}";
        file_put_contents($path, '%PDF-1.4 second');
        $env->pdo()->prepare("INSERT INTO gs_request_documents (gs_request_id, doc_type, stored_filename, original_filename, mime_type, size_bytes) VALUES (?, 'supporting', ?, 'extra.pdf', 'application/pdf', 15)")->execute([$caseId, $stored]);
        return [$stored, $path];
    };
    $auditCount = fn (string $action, int $caseId): int => (int) $env->scalar('SELECT COUNT(*) FROM audit_log WHERE action = ? AND entity_id = ?', [$action, $caseId]);
    $auditMeta  = fn (string $action, int $caseId): ?array => json_decode((string) $env->scalar('SELECT metadata_json FROM audit_log WHERE action = ? AND entity_id = ? ORDER BY id DESC LIMIT 1', [$action, $caseId]), true);
    $leftoverPage = fn () => http_request('GET', $env->baseUrl . '/admin/data-erasure/', ['cookie_jar' => $env->cookieJarStaff])['body'];

    run_test('a normal erasure records the files it deleted in the audit trail and leaves nothing listed as leftover', function () use ($env, $makeCase, $erase, $auditCount, $auditMeta, $leftoverPage) {
        [$id, $file] = $makeCase('closed');
        $name = basename($file);
        $erase($id, 'ERASE');
        clearstatcache();
        assert_true(!is_file($file), 'the file is deleted');
        assert_equal([$name], $auditMeta('case_erased', $id)['files'], 'the case_erased entry must name the files, as the durable record');
        assert_equal(1, $auditCount('case_erasure_files_deleted', $id), 'the all-files-gone marker is written');
        assert_equal(0, $auditCount('case_erasure_files_failed', $id));
        assert_true(strpos($leftoverPage(), $name) === false, 'a fully deleted case must not appear as leftover');
    });

    run_test('a case with no documents erases cleanly without any file records', function () use ($env, $erase, $auditCount, $auditMeta) {
        $pdo = $env->pdo();
        $pdo->exec("INSERT INTO gs_requests (source, owner_full_name, owner_email, owner_phone, owner_address, owner_city, owner_state, owner_pin, patient_name, requested_formulation, status)
                    VALUES ('cat_owner','No Docs','nodocs@example.test','9999999999','x','x','x','000000','Nobody','oral','closed')");
        $id = (int) $pdo->lastInsertId();
        $pdo = null;
        assert_contains("GS-{$id} erased", $erase($id, 'ERASE')['body']);
        assert_true(!isset($auditMeta('case_erased', $id)['files']), 'no files, so no file list');
        assert_equal(0, $auditCount('case_erasure_files_deleted', $id) + $auditCount('case_erasure_files_failed', $id), 'and no file-outcome entries');
    });

    run_test('a delete that fails is reported by name, audited, and the file is listed as leftover', function () use ($env, $makeCase, $addDocument, $runWorker, $auditCount, $auditMeta, $leftoverPage) {
        [$id, $fileA] = $makeCase('closed');
        [$nameB, $fileB] = $addDocument($id);
        $nameA = basename($fileA);

        $r = $runWorker('erase', $id, $nameB);

        assert_equal(true, $r['ok'], 'the database part of the erasure did succeed');
        assert_equal([$nameB], $r['failed_files'], 'the file that could not be deleted is returned by name');
        assert_contains('could NOT be deleted', $r['message']);
        assert_contains($nameB, $r['message']);
        assert_contains("GS-{$id}", $r['message']);
        assert_true(strpos($r['message'], 'uploaded document(s) deleted') === false, 'it must not claim the documents were deleted');
        clearstatcache();
        assert_true(!is_file($fileA), 'the other file is deleted');
        assert_true(is_file($fileB), 'the failed one is still on disk');
        assert_true($env->scalar('SELECT erased_at FROM gs_requests WHERE id = ?', [$id]) !== null, 'the case itself is erased');

        assert_equal([$nameA, $nameB], $auditMeta('case_erased', $id)['files'], 'both names are in the durable record');
        assert_equal([$nameB], $auditMeta('case_erasure_files_failed', $id)['files'], 'the failure entry names only the failed file');
        assert_equal(0, $auditCount('case_erasure_files_deleted', $id), 'no all-clear marker while a file remains');

        $page = $leftoverPage();
        assert_contains($nameB, $page);
        assert_contains("gs-requests/{$id}/{$nameB}", $page);
        assert_true(strpos($page, $nameA) === false, 'the deleted file is not listed');
        assert_contains('Delete leftover files', $page);

        // Tidy: remove the deliberately stuck file so it isn't a leftover in the next test.
        @unlink($fileB);
    });

    run_test('retrying leftovers: a failing retry keeps them listed, a working one deletes them, marks the case done, and is a no-op afterwards', function () use ($env, $makeCase, $addDocument, $runWorker, $postAndFollow, $auditCount, $auditMeta, $leftoverPage) {
        [$id, $fileA] = $makeCase('closed');
        [$nameB, $fileB] = $addDocument($id);
        $runWorker('erase', $id, $nameB);
        assert_true(is_file($fileB));

        // The retry fails too: reported, still listed, still no all-clear.
        $r = $runWorker('leftovers', 0, $nameB);
        assert_equal(0, $r['deleted']);
        assert_equal(["GS-{$id}: {$nameB}"], $r['failed']);
        assert_contains('could NOT be deleted', $r['message']);
        assert_true(is_file($fileB));
        assert_contains($nameB, $leftoverPage());
        assert_equal(0, $auditCount('case_erasure_files_deleted', $id));

        // The real retry, through the page's button (POST, redirect, message shown once).
        $res = $postAndFollow(['action' => 'delete_leftovers']);
        assert_equal(302, $res['post']['status'], 'the button POST is answered with a redirect');
        assert_contains('Deleted', $res['body']);
        clearstatcache();
        assert_true(!is_file($fileB), 'the leftover file is deleted');
        assert_equal(1, $auditCount('case_erasure_files_deleted', $id), 'the case is marked done');
        assert_equal('leftover_retry', $auditMeta('case_erasure_files_deleted', $id)['via']);
        assert_true(strpos($leftoverPage(), $nameB) === false, 'and it is no longer listed');

        // Nothing left: pressing it again does nothing, and says so.
        $again = $postAndFollow(['action' => 'delete_leftovers']);
        assert_contains('no leftover files to delete', $again['body']);
    });

    run_test('a file left behind because the process died after the commit (no failure entry at all) is found and deleted', function () use ($env, $makeCase, $postAndFollow, $auditCount, $leftoverPage) {
        // Only the durable record exists: case_erased with the file list, the case marked erased, the
        // file still on disk, and neither a deleted nor a failed entry - as if the server stopped
        // right after the commit.
        [$id, $file] = $makeCase('closed');
        $name = basename($file);
        $pdo = $env->pdo();
        $pdo->prepare('UPDATE gs_requests SET erased_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s'), $id]);
        $pdo->prepare("INSERT INTO audit_log (actor_type, action, entity_type, entity_id, metadata_json) VALUES ('staff', 'case_erased', 'gs_request', ?, ?)")
            ->execute([$id, json_encode(['documents_deleted' => 1, 'files' => [$name]])]);
        $pdo = null;

        assert_contains($name, $leftoverPage());
        $postAndFollow(['action' => 'delete_leftovers']);
        clearstatcache();
        assert_true(!is_file($file), 'the orphaned file is deleted');
        assert_equal(1, $auditCount('case_erasure_files_deleted', $id));
    });

    run_test('the leftover cleanup only ever touches files named in an erasure record: tampered names, unrecorded files and other cases are safe', function () use ($env, $makeCase, $postAndFollow, $leftoverPage) {
        // A bystander file outside storage that a "../" name would point at, an unrecorded file inside an
        // erased case's own folder, and a live (open) case's file.
        $outside = dirname($env->storageDir) . '/outside_erasure_test.txt';
        file_put_contents($outside, 'must survive');
        [$erasedId, $recordedFile] = $makeCase('closed');
        $unrecorded = $env->storageDir . "/gs-requests/{$erasedId}/not-in-any-record.pdf";
        file_put_contents($unrecorded, 'must survive');
        [$openId, $openFile] = $makeCase('under_review');

        $pdo = $env->pdo();
        $pdo->prepare('UPDATE gs_requests SET erased_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s'), $erasedId]);
        $pdo->prepare("INSERT INTO audit_log (actor_type, action, entity_type, entity_id, metadata_json) VALUES ('staff', 'case_erased', 'gs_request', ?, ?)")
            ->execute([$erasedId, json_encode(['files' => ['../../../outside_erasure_test.txt', '/etc/passwd', '..', basename($recordedFile)]])]);
        $pdo = null;

        $page = $leftoverPage();
        assert_true(strpos($page, 'outside_erasure_test') === false, 'a tampered name is never listed');
        assert_contains(basename($recordedFile), $page);

        $postAndFollow(['action' => 'delete_leftovers']);
        clearstatcache();
        assert_true(is_file($outside), 'a "../" name must not reach outside the storage folder');
        assert_true(is_file($unrecorded), 'a file the record does not name is left alone');
        assert_true(is_file($openFile), 'an open case that was never erased is left alone');
        assert_true(!is_file($recordedFile), 'only the recorded file is deleted');
        @unlink($outside);
    });

    run_test('the leftover cleanup needs a valid CSRF token', function () use ($env, $makeCase) {
        [$id, $file] = $makeCase('closed');
        $pdo = $env->pdo();
        $pdo->prepare('UPDATE gs_requests SET erased_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s'), $id]);
        $pdo->prepare("INSERT INTO audit_log (actor_type, action, entity_type, entity_id, metadata_json) VALUES ('staff', 'case_erased', 'gs_request', ?, ?)")
            ->execute([$id, json_encode(['files' => [basename($file)]])]);
        $pdo = null;

        $post = http_request('POST', $env->baseUrl . '/admin/data-erasure/', ['cookie_jar' => $env->cookieJarStaff, 'body' => ['csrf_token' => 'wrong', 'action' => 'delete_leftovers']]);
        $followed = http_request('GET', $env->baseUrl . $post['location'], ['cookie_jar' => $env->cookieJarStaff]);
        assert_contains('session expired', $followed['body']);
        assert_true(is_file($file), 'nothing may be deleted without a valid token');

        // Tidy: clear this record so it doesn't show up as a leftover in later tests.
        @unlink($file);
    });

    run_test('the page shows a file that could not be deleted as an error, not a success', function () use ($env) {
        $page = file_get_contents($env->root . '/public_html/admin/data-erasure/index.php');
        assert_contains("empty(\$res['failed_files'])", $page, 'the flash type must depend on failed_files');
        assert_contains('Leftover files from erasures', $page);
    });

    // An erased case is a record, not a working case: the page shows no forms and the server refuses every
    // POST, so personal details cannot be typed back in (an internal note, the status note, the closure reason
    // or tracking number that erasure had just blanked) and nothing can be emailed to "[erased]".
    run_test('an erased case is read-only on the server: every action is refused and nothing is written', function () use ($env, $makeCase, $erase) {
        [$id] = $makeCase('rejected');
        $erase($id, 'ERASE');
        $jar = $env->cookieJarStaff;
        $adminId = (int) $env->scalar("SELECT id FROM staff_users WHERE email = 'admin@example.test'");
        $snapshot = function () use ($env, $id): array {
            $s = $env->pdo()->prepare('SELECT status, lock_version, assigned_staff_id, final_formulation, final_price, courier, tracking_number, closure_reason FROM gs_requests WHERE id = ?');
            $s->execute([$id]);
            $r = $s->fetch(PDO::FETCH_ASSOC);
            $s->closeCursor();
            return $r + [
                'notes' => (int) $env->scalar('SELECT COUNT(*) FROM case_internal_notes WHERE gs_request_id = ?', [$id]),
                'history' => (int) $env->scalar('SELECT COUNT(*) FROM case_status_history WHERE gs_request_id = ?', [$id]),
                'emails' => (int) $env->scalar('SELECT COUNT(*) FROM case_emails WHERE gs_request_id = ?', [$id]),
                'audit' => (int) $env->scalar("SELECT COUNT(*) FROM audit_log WHERE entity_type = 'gs_request' AND entity_id = ? AND action <> 'case_erased'", [$id]),
            ];
        };
        $before = $snapshot();
        // The page itself has no forms (so no token there); a real attacker would take one from any other page.
        $csrf = extract_csrf(http_request('GET', $env->baseUrl . '/admin/gs-requests/', ['cookie_jar' => $jar])['body']);
        $lock = $before['lock_version'];
        $secret = 'Owner phone 9999999999';
        $attempts = [
            'add_note'        => ['content' => $secret],
            'change_status'   => ['new_status' => 'submitted', 'status_note' => $secret, 'expected_lock_version' => $lock],
            'update_final'    => ['final_formulation' => 'Changed', 'final_concentration' => '', 'final_quantity' => '', 'final_price' => '99', 'courier' => 'X',
                                  'tracking_number' => $secret, 'dispatch_date' => '', 'closure_reason' => $secret, 'expected_lock_version' => $lock],
            'assign_staff'    => ['assigned_staff_id' => $adminId, 'expected_lock_version' => $lock],
            'send_email'      => ['ott' => 'x', 'sender' => 'ops@kuronyx.in', 'recipient' => '[erased]', 'subject' => 'x', 'body' => 'y'],
            'no_email_needed' => ['ott' => 'x'],
        ];
        foreach ($attempts as $action => $fields) {
            $post = http_request('POST', $env->baseUrl . "/admin/gs-requests/view.php?id={$id}", ['follow' => true, 'cookie_jar' => $jar, 'body' => array_merge(['csrf_token' => $csrf, 'action' => $action], $fields)]);
            assert_contains('has been erased, so it is read-only', $post['body'], "{$action} must be refused");
            assert_true(strpos($post['body'], $secret) === false, "{$action}: what was typed must not be shown back");
            assert_equal($before, $snapshot(), "{$action} must not have changed anything");
        }
    });

    run_test('an erased case page shows no forms, keeps the history and the kept details, and does not call an unknown "neutered" a "No"', function () use ($env, $makeCase, $erase) {
        [$id] = $makeCase('closed');
        $env->pdo()->prepare('UPDATE gs_requests SET patient_neutered = 1, final_price = 1200, courier = ? WHERE id = ?')->execute(['BlueDart', $id]);
        $erase($id, 'ERASE'); // sets patient_neutered back to NULL
        $adminId = (int) $env->scalar("SELECT id FROM staff_users WHERE email = 'admin@example.test'");
        $adminName = $env->scalar('SELECT name FROM staff_users WHERE id = ?', [$adminId]);
        $env->pdo()->prepare('UPDATE gs_requests SET assigned_staff_id = ? WHERE id = ?')->execute([$adminId, $id]);
        $page = http_request('GET', $env->baseUrl . "/admin/gs-requests/view.php?id={$id}", ['cookie_jar' => $env->cookieJarStaff])['body'];

        assert_contains('The case is read-only.', $page);
        foreach (['update_final', 'change_status', 'assign_staff', 'add_note', 'send_email', 'no_email_needed'] as $action) {
            assert_true(strpos($page, 'value="' . $action . '"') === false, "no {$action} form on an erased case");
        }
        foreach (['<textarea', 'name="recipient"', 'name="new_status"', 'name="assigned_staff_id"', 'name="final_price"', 'Owner ' . "\u{2014}" . ' '] as $gone) {
            assert_true(strpos($page, $gone) === false, "{$gone} must not be on an erased case");
        }
        // Kept on purpose: the status history, and the formulation/price/courier erasure does not touch.
        assert_contains('<h2>Status history</h2>', $page);
        assert_true((bool) preg_match('#<div class="note-body">Closed</div>#', $page), 'the history entry is still shown');
        assert_true((bool) preg_match('#<div class="k">Final formulation</div><div class="v">GS oral 30mg</div>#', $page), 'the kept formulation is shown as plain text');
        assert_true((bool) preg_match('#<div class="k">Price \(INR\)</div><div class="v">1,200.00</div>#', $page));
        assert_true((bool) preg_match('#<div class="k">Courier</div><div class="v">BlueDart</div>#', $page));
        assert_true((bool) preg_match('#<div class="k">Assigned to</div><div class="v">' . preg_quote(htmlspecialchars($adminName, ENT_QUOTES), '#') . '</div>#', $page), 'who it is assigned to is shown, not editable');
        // An unknown answer is not "No", and it is a plain hyphen.
        assert_true((bool) preg_match('#<div class="k">Neutered / spayed</div><div class="v">-</div>#', $page), 'NULL shows a plain hyphen');
    });

    run_test('a case that is not erased still shows its forms, and neutered Yes/No are unchanged', function () use ($env, $makeCase) {
        [$id] = $makeCase('submitted');
        $view = fn () => http_request('GET', $env->baseUrl . "/admin/gs-requests/view.php?id={$id}", ['cookie_jar' => $env->cookieJarStaff])['body'];
        $page = $view();
        foreach (['update_final', 'change_status', 'assign_staff', 'add_note'] as $action) {
            assert_contains('value="' . $action . '"', $page, "the {$action} form is there on a live case");
        }
        assert_true(strpos($page, 'The case is read-only.') === false);
        foreach ([['0', 'No'], ['1', 'Yes']] as [$stored, $shown]) {
            $env->pdo()->prepare('UPDATE gs_requests SET patient_neutered = ? WHERE id = ?')->execute([$stored, $id]);
            assert_true((bool) preg_match('#<div class="k">Neutered / spayed</div><div class="v">' . $shown . '</div>#', $view()), "{$stored} shows {$shown}");
        }
    });

    run_test('data erasure is admin-only', function () use ($env) {
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
