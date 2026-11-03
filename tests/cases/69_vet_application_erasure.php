<?php
// Erasing a veterinary application (includes/data-protection.php erase_vet_application(); admin/data-erasure/).
// There used to be no way to delete an applicant's data: the erasure tool only covered GS cases, and deleting
// the rows by hand would be refused (the vet account is referenced by cases) and would leave the uploaded
// registration certificate on disk. The rules this file pins down: what is removed, what is deliberately kept
// (the cases the vet submitted), that an approved application must be suspended first, that an erased
// application is read-only, and that a failed file delete is reported and can be retried.
return function (TestEnv $env): void {
    $jar = $env->cookieJarStaff;

    // An application with a certificate on disk, a portal account (with live tokens), a case the vet submitted
    // and audit entries of each kind. Returns the ids and the stored file.
    $makeApp = function (string $status = 'rejected', ?string $reviewedAt = null) use ($env): array {
        $pdo = $env->pdo();
        $uniq = bin2hex(random_bytes(3));
        $pdo->prepare(
            "INSERT INTO vet_applications (full_name, professional_email, mobile, registration_number, registration_state, qualification, year_qualified,
                 practice_type, clinic_name, clinic_address, clinic_city, clinic_state, clinic_pin, clinic_phone, clinic_email, clinic_website,
                 status, internal_notes, reviewed_at)
             VALUES ('Dr Erase Me', ?, '9000011111', 'MH-VET-777', 'Maharashtra', 'BVSc', 2015,
                 'Small animal', 'Erase Clinic', '5 Clinic Rd', 'Pune', 'MH', '411001', '02012345678', 'clinic@erase.example.test', 'https://erase.example.test',
                 ?, 'Called the clinic on 02012345678; certificate checked.', ?)"
        )->execute(["erase.vet.{$uniq}@example.test", $status, $reviewedAt ?? gmdate('Y-m-d H:i:s')]);
        $appId = (int) $pdo->lastInsertId();

        // The proof of consent (applicant's IP address and browser) that the form saves with every application.
        $pdo->prepare("INSERT INTO vet_application_consents (application_id, consent_type, consent_text_version, ip_address, user_agent) VALUES (?, 'application_processing', 'pp-test', '203.0.113.9', 'Erase Browser/1.0')")->execute([$appId]);

        $stored = bin2hex(random_bytes(8)) . '.pdf';
        $dir = $env->storageDir . "/vet-applications/{$appId}";
        mkdir($dir, 0777, true);
        file_put_contents("{$dir}/{$stored}", '%PDF-1.4 certificate');
        $pdo->prepare("INSERT INTO vet_application_documents (application_id, stored_filename, original_filename, mime_type, size_bytes) VALUES (?, ?, 'registration.pdf', 'application/pdf', 20)")->execute([$appId, $stored]);

        $pdo->prepare(
            "INSERT INTO vet_accounts (vet_application_id, email, password_hash, status, activation_token_hash, activation_expires_at, password_reset_token_hash, password_reset_expires_at)
             VALUES (?, ?, ?, 'active', ?, ?, ?, ?)"
        )->execute([$appId, "erase.vet.{$uniq}@example.test", password_hash('vet-erase-password-1', PASSWORD_DEFAULT), hash('sha256', "act-{$uniq}"), gmdate('Y-m-d H:i:s', time() + 3600), hash('sha256', "rst-{$uniq}"), gmdate('Y-m-d H:i:s', time() + 3600)]);
        $acctId = (int) $env->scalar('SELECT id FROM vet_accounts WHERE vet_application_id = ?', [$appId]);

        // A case the vet submitted through the portal: it holds the prescriber's details and points at the account.
        $pdo->prepare(
            "INSERT INTO gs_requests (source, owner_full_name, owner_email, owner_phone, owner_address, owner_city, owner_state, owner_pin,
                 patient_name, requested_formulation, status, vet_name, vet_clinic, vet_email, vet_account_id)
             VALUES ('veterinarian', 'Pet Owner', 'owner@example.test', '9999999999', '1 Road', 'Pune', 'MH', '411001',
                 'Kitty', 'oral', 'submitted', 'Dr Erase Me', 'Erase Clinic', 'erase.vet@example.test', ?)"
        )->execute([$acctId]);
        $caseId = (int) $env->scalar('SELECT id FROM gs_requests WHERE vet_account_id = ?', [$acctId]);

        $audit = $pdo->prepare('INSERT INTO audit_log (actor_type, actor_id, actor_name, action, entity_type, entity_id, metadata_json) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $audit->execute(['public', null, null, 'application_submitted', 'vet_application', $appId, '{"source_ip":"203.0.113.9"}']);
        $audit->execute(['staff', 1, 'Reviewer Staff', 'email_sent', 'vet_application', $appId, '{"recipient":"erase.vet@example.test"}']);
        $audit->execute(['vet', $acctId, 'Dr Erase Me', 'vet_login', 'vet_account', $acctId, '{"ua":"x"}']);
        $audit->execute(['vet', $acctId, 'Dr Erase Me', 'case_submitted', 'gs_request', $caseId, '{"source":"veterinarian"}']);
        return ['app' => $appId, 'acct' => $acctId, 'case' => $caseId, 'file' => "{$dir}/{$stored}", 'stored' => $stored];
    };

    // The page answers with a redirect and shows the outcome on the next GET; follow it, like a person sees it.
    $post = function (array $body) use ($env, $jar) {
        $get = http_request('GET', $env->baseUrl . '/admin/data-erasure/', ['cookie_jar' => $jar]);
        $r = http_request('POST', $env->baseUrl . '/admin/data-erasure/', ['cookie_jar' => $jar, 'body' => ['csrf_token' => extract_csrf($get['body'])] + $body]);
        if ($r['status'] !== 302) return $r;
        return http_request('GET', $env->baseUrl . $r['location'], ['cookie_jar' => $jar]);
    };
    $eraseApp = fn (int $id, string $confirm = 'ERASE', array $extra = []) => $post(['kind' => 'vet_application', 'application_id' => $id, 'confirm' => $confirm] + $extra);
    $row = function (string $sql, array $params) use ($env): array {
        $s = $env->pdo()->prepare($sql);
        $s->execute($params);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        $s->closeCursor();
        return $r ?: [];
    };
    $auditMeta = fn (string $action, int $id): ?array => json_decode((string) $env->scalar('SELECT metadata_json FROM audit_log WHERE action = ? AND entity_id = ? ORDER BY id DESC LIMIT 1', [$action, $id]), true);
    $auditCount = fn (string $action, int $id): int => (int) $env->scalar('SELECT COUNT(*) FROM audit_log WHERE action = ? AND entity_id = ?', [$action, $id]);

    run_test('erasing a rejected application deletes the certificate and the personal details, neutralises the account and keeps the record', function () use ($env, $makeApp, $eraseApp, $row, $auditMeta, $auditCount) {
        $a = $makeApp('rejected');
        $other = $makeApp('pending'); // another application: its consent record must not be touched
        assert_equal(1, (int) $env->scalar('SELECT COUNT(*) FROM vet_application_consents WHERE application_id = ?', [$a['app']]), 'the applicant has a consent record before erasure');
        assert_true(is_file($a['file']));
        $res = $eraseApp($a['app']);
        assert_contains("Application VA-{$a['app']} erased", $res['body']);
        assert_equal(0, (int) $env->scalar('SELECT COUNT(*) FROM vet_application_consents WHERE application_id = ?', [$a['app']]), 'the consent record (IP address and browser) is deleted with the application');
        assert_equal(1, (int) $env->scalar('SELECT COUNT(*) FROM vet_application_consents WHERE application_id = ?', [$other['app']]), 'another application keeps its consent record');
        assert_contains('1 case(s) this veterinarian submitted still hold their prescriber details', $res['body']);
        assert_contains('portal account locked out', $res['body']);

        clearstatcache();
        assert_true(!is_file($a['file']), 'the registration certificate must be deleted from disk');
        assert_equal(0, (int) $env->scalar('SELECT COUNT(*) FROM vet_application_documents WHERE application_id = ?', [$a['app']]));

        $app = $row('SELECT * FROM vet_applications WHERE id = ?', [$a['app']]);
        foreach (['full_name', 'professional_email', 'mobile', 'registration_number', 'registration_state', 'qualification', 'practice_type', 'clinic_name', 'clinic_address', 'clinic_city', 'clinic_state', 'clinic_pin', 'clinic_phone'] as $c) {
            assert_equal('[erased]', $app[$c], "{$c} must be blanked");
        }
        assert_true($app['clinic_email'] === null && $app['clinic_website'] === null && $app['internal_notes'] === null, 'optional fields and the review notes are cleared');
        assert_equal(0, (int) $app['year_qualified']);
        assert_true($app['erased_at'] !== null, 'erased_at is stamped');
        assert_equal('rejected', $app['status'], 'the status is kept');
        assert_true($app['reviewed_at'] !== null, 'when it was decided is kept');

        // The account is locked and anonymised, not deleted (the vet's cases point at it).
        $acct = $row('SELECT * FROM vet_accounts WHERE id = ?', [$a['acct']]);
        assert_equal("erased-vet-{$a['acct']}@erased.invalid", $acct['email']);
        assert_true($acct['password_hash'] === null && $acct['activation_token_hash'] === null && $acct['activation_expires_at'] === null
            && $acct['password_reset_token_hash'] === null && $acct['password_reset_expires_at'] === null, 'password and every token are gone');
        assert_equal('suspended', $acct['status']);

        // Audit: details cleared on the entries about the application and account, the vet's NAME cleared on the vet's
        // own actions, the staff reviewer's name kept (they are not the person being erased).
        assert_true($env->scalar("SELECT metadata_json FROM audit_log WHERE action = 'application_submitted' AND entity_id = ?", [$a['app']]) === null, 'the IP in the audit metadata is cleared');
        assert_true($env->scalar("SELECT metadata_json FROM audit_log WHERE action = 'email_sent' AND entity_id = ?", [$a['app']]) === null, 'the recipient address in the audit metadata is cleared');
        assert_true($env->scalar("SELECT metadata_json FROM audit_log WHERE action = 'vet_login' AND entity_id = ?", [$a['acct']]) === null);
        assert_true($env->scalar("SELECT actor_name FROM audit_log WHERE action = 'vet_login' AND entity_id = ?", [$a['acct']]) === null, 'the vet\'s name is cleared from their own login entry');
        assert_true($env->scalar("SELECT actor_name FROM audit_log WHERE action = 'case_submitted' AND entity_id = ?", [$a['case']]) === null, 'and from the case submission they made');
        assert_equal((int) $a['acct'], (int) $env->scalar("SELECT actor_id FROM audit_log WHERE action = 'case_submitted' AND entity_id = ?", [$a['case']]), 'the entry and the id stay');
        assert_equal('Reviewer Staff', $env->scalar("SELECT actor_name FROM audit_log WHERE action = 'email_sent' AND entity_id = ?", [$a['app']]), 'a staff member\'s name is kept');
        assert_equal(1, $auditCount('vet_application_erased', $a['app']), 'the erasure itself is audited');
        $meta = $auditMeta('vet_application_erased', $a['app']);
        assert_equal([$a['stored']], $meta['files'], 'the entry names the files, as the durable record');
        assert_equal(1, (int) $meta['cases_still_holding_prescriber_details']);
        assert_equal(1, $auditCount('vet_application_erasure_files_deleted', $a['app']), 'the all-files-gone marker is written');
    });

    run_test('the cases the vet submitted are NOT changed: they are prescription records that clear the prescriber on their own schedule', function () use ($env, $makeApp, $eraseApp, $row) {
        $a = $makeApp('suspended');
        $before = $row('SELECT vet_name, vet_clinic, vet_email, vet_account_id, owner_full_name, status FROM gs_requests WHERE id = ?', [$a['case']]);
        $eraseApp($a['app']);
        assert_equal($before, $row('SELECT vet_name, vet_clinic, vet_email, vet_account_id, owner_full_name, status FROM gs_requests WHERE id = ?', [$a['case']]));
        assert_equal('Dr Erase Me', $before['vet_name']);
    });

    run_test('every status except approved can be erased; an approved application must be suspended first and is left untouched', function () use ($env, $makeApp, $eraseApp, $row) {
        $approved = $makeApp('approved');
        $res = $eraseApp($approved['app']);
        assert_contains('is approved, so its portal account may be in use. Suspend it first', $res['body']);
        assert_equal('Dr Erase Me', $row('SELECT full_name FROM vet_applications WHERE id = ?', [$approved['app']])['full_name'], 'nothing was erased');
        assert_true(is_file($approved['file']), 'and the certificate is still there');
        assert_equal('active', $row('SELECT status FROM vet_accounts WHERE id = ?', [$approved['acct']])['status'], 'and the account is untouched');
        // After suspending it, the same request works.
        $env->pdo()->prepare("UPDATE vet_applications SET status = 'suspended' WHERE id = ?")->execute([$approved['app']]);
        assert_contains("Application VA-{$approved['app']} erased", $eraseApp($approved['app'])['body']);

        foreach (['pending', 'under_review', 'suspended'] as $status) {
            $a = $makeApp($status);
            assert_contains("Application VA-{$a['app']} erased", $eraseApp($a['app'])['body'], "{$status} can be erased");
        }
    });

    run_test('the confirmation safeguards: ERASE must be typed (trimmed, case-sensitive), the dialog number must match its row, unknown and already-erased are reported', function () use ($env, $makeApp, $eraseApp, $post, $row) {
        $a = $makeApp('rejected');
        assert_contains('Type ERASE', $eraseApp($a['app'], 'erase')['body']);
        assert_contains('Type ERASE', $eraseApp($a['app'], '')['body']);
        assert_true(is_file($a['file']), 'nothing erased without the typed word');
        assert_contains('Enter a valid application number', $eraseApp(0)['body']);
        assert_contains('does not exist', $eraseApp(99999999)['body']);

        // The dialog posts expected_application_id (the clicked row): a different number typed is refused.
        $other = $makeApp('rejected');
        $mismatch = $eraseApp($other['app'], 'ERASE', ['expected_application_id' => $a['app']]);
        assert_contains("does not match VA-{$a['app']}. Nothing was erased.", $mismatch['body']);
        assert_equal('Dr Erase Me', $row('SELECT full_name FROM vet_applications WHERE id = ?', [$other['app']])['full_name']);

        // Stray spaces around ERASE (copy-paste) are fine; the second attempt reports it is already done.
        assert_contains("Application VA-{$a['app']} erased", $eraseApp($a['app'], ' ERASE ', ['expected_application_id' => $a['app']])['body']);
        assert_contains('has already been erased', $eraseApp($a['app'])['body']);
    });

    run_test('the page lists only REJECTED applications past 12 months, with a row button and a dialog, and the erase-a-case form is unchanged', function () use ($env, $makeApp, $eraseApp, $jar) {
        $old   = $makeApp('rejected', gmdate('Y-m-d H:i:s', strtotime('-13 months')));
        $fresh = $makeApp('rejected', gmdate('Y-m-d H:i:s', strtotime('-2 months')));
        $oldApproved = $makeApp('approved', gmdate('Y-m-d H:i:s', strtotime('-30 months')));
        $oldPending  = $makeApp('pending', gmdate('Y-m-d H:i:s', strtotime('-30 months')));
        $page = http_request('GET', $env->baseUrl . '/admin/data-erasure/', ['cookie_jar' => $jar])['body'];
        assert_contains("data-erase-application=\"{$old['app']}\"", $page, 'a rejected application over 12 months old is listed');
        foreach ([$fresh, $oldApproved, $oldPending] as $notDue) {
            assert_true(strpos($page, "data-erase-application=\"{$notDue['app']}\"") === false, "VA-{$notDue['app']} is not past retention");
        }
        assert_contains('Applications rejected over 12 months ago', $page);
        assert_contains('<dialog id="erase-vet-dialog"', $page);
        assert_contains('name="kind" value="vet_application"', $page);
        assert_contains('<dialog id="erase-dialog"', $page, 'the case dialog is still there');
        assert_contains('Erase a case', $page);
        // Once erased it drops off the list.
        $eraseApp($old['app']);
        $after = http_request('GET', $env->baseUrl . '/admin/data-erasure/', ['cookie_jar' => $jar])['body'];
        assert_true(strpos($after, "data-erase-application=\"{$old['app']}\"") === false, 'an erased application leaves the list');
    });

    run_test('an erased application is read-only: no forms on its page and every action is refused on the server, so it cannot be approved again', function () use ($env, $makeApp, $eraseApp, $row, $jar) {
        $a = $makeApp('rejected');
        $eraseApp($a['app']);
        $view = http_request('GET', $env->baseUrl . "/admin/veterinary-applications/view.php?id={$a['app']}", ['cookie_jar' => $jar])['body'];
        assert_contains('The application is read-only.', $view);
        foreach (['<textarea', 'name="recipient"', 'value="approve"', 'value="send_email"', 'Before you approve', 'Review actions'] as $gone) {
            assert_true(strpos($view, $gone) === false, "{$gone} must not be on an erased application");
        }
        assert_true(strpos($view, 'Called the clinic') === false && strpos($view, 'Dr Erase Me') === false, 'nothing personal is shown');

        $snapshot = fn () => [
            $row('SELECT status, internal_notes, full_name, reviewed_by FROM vet_applications WHERE id = ?', [$a['app']]),
            $row('SELECT status, password_hash, email FROM vet_accounts WHERE id = ?', [$a['acct']]),
            (int) $env->scalar("SELECT COUNT(*) FROM audit_log WHERE entity_type = 'vet_application' AND entity_id = ? AND action <> 'vet_application_erased' AND created_at >= ?", [$a['app'], gmdate('Y-m-d H:i:s', time() - 5)]),
        ];
        $before = $snapshot();
        $csrf = extract_csrf(http_request('GET', $env->baseUrl . '/admin/veterinary-applications/', ['cookie_jar' => $jar])['body']);
        $secret = 'Spoke to Dr Erase Me on 9000011111';
        foreach (['approve', 'reject', 'request_info', 'suspend', 'generate_activation_link', 'send_email', 'no_email_needed', 'add_note'] as $action) {
            $r = http_request('POST', $env->baseUrl . "/admin/veterinary-applications/view.php?id={$a['app']}", ['follow' => true, 'cookie_jar' => $jar, 'body' => [
                'csrf_token' => $csrf, 'action' => $action, 'note' => $secret, 'ott' => 'x',
                'sender' => 'ops@kuronyx.in', 'recipient' => 'x@example.test', 'subject' => 'x', 'body' => 'y',
            ]]);
            assert_contains('has been erased, so it is read-only', $r['body'], "{$action} must be refused");
            assert_true(strpos($r['body'], $secret) === false, "{$action}: what was typed must not be shown back");
            assert_equal($before, $snapshot(), "{$action} must not have changed anything");
        }
        assert_equal('rejected', $row('SELECT status FROM vet_applications WHERE id = ?', [$a['app']])['status']);
        assert_equal('suspended', $row('SELECT status FROM vet_accounts WHERE id = ?', [$a['acct']])['status'], 'the account stays locked out');
    });

    // ---- A failed or interrupted file delete: reported, listed as leftover, retried ----
    $runWorker = function (string $task, int $id, string $failNames = '') use ($env): array {
        $pipes = [];
        $proc = proc_open(['php', '-c', $env->iniPath, $env->root . '/tests/lib/erasure_worker.php', $task, (string) $id, $failNames],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]);
        $text = trim(stream_get_contents($pipes[1]));
        $log  = trim(stream_get_contents($pipes[2]));
        fclose($pipes[1]); fclose($pipes[2]);
        proc_close($proc);
        $data = json_decode($text, true);
        assert_true(is_array($data), "the erasure worker did not return JSON: {$text} {$log}");
        return $data;
    };

    run_test('a certificate that cannot be deleted is reported by name, audited, listed as leftover for VA-n, and a retry deletes it', function () use ($env, $makeApp, $runWorker, $post, $auditCount, $auditMeta, $jar) {
        $a = $makeApp('rejected');
        $res = $runWorker('erase_vet', $a['app'], $a['stored']);
        assert_true($res['ok'], 'the database part succeeded');
        assert_equal([$a['stored']], $res['failed_files']);
        assert_contains('could NOT be deleted', $res['message']);
        assert_true(is_file($a['file']), 'the file is still there');
        assert_equal(1, $auditCount('vet_application_erasure_files_failed', $a['app']));
        assert_equal(0, $auditCount('vet_application_erasure_files_deleted', $a['app']));
        assert_equal([$a['stored']], $auditMeta('vet_application_erased', $a['app'])['files'], 'the durable record names it');

        $page = http_request('GET', $env->baseUrl . '/admin/data-erasure/', ['cookie_jar' => $jar])['body'];
        assert_contains($a['stored'], $page, 'listed as a leftover');
        assert_contains(">VA-{$a['app']}</a>", $page, 'labelled VA-n, linking to the application');
        assert_contains("/admin/veterinary-applications/view.php?id={$a['app']}", $page);

        // The page's retry button (no deleter failing now) removes it and writes the marker.
        $retry = $post(['action' => 'delete_leftovers']);
        assert_contains('Deleted', $retry['body']);
        clearstatcache();
        assert_true(!is_file($a['file']), 'the retry deleted the file');
        assert_equal(1, $auditCount('vet_application_erasure_files_deleted', $a['app']));
        assert_true(strpos(http_request('GET', $env->baseUrl . '/admin/data-erasure/', ['cookie_jar' => $jar])['body'], $a['stored']) === false, 'no longer listed');
    });

    run_test('case erasure still works beside it: its leftovers keep the GS-n label (the shared helpers serve both)', function () use ($env, $runWorker, $post, $jar) {
        $pdo = $env->pdo();
        $pdo->exec("INSERT INTO gs_requests (source, owner_full_name, owner_email, owner_phone, owner_address, owner_city, owner_state, owner_pin, patient_name, requested_formulation, status)
                    VALUES ('cat_owner','Case Leftover','caseleft@example.test','9999999999','x','x','x','000000','Nobody','oral','closed')");
        $id = (int) $pdo->lastInsertId();
        $stored = bin2hex(random_bytes(8)) . '.pdf';
        mkdir($env->storageDir . "/gs-requests/{$id}", 0777, true);
        $file = $env->storageDir . "/gs-requests/{$id}/{$stored}";
        file_put_contents($file, '%PDF-1.4 x');
        $pdo->prepare("INSERT INTO gs_request_documents (gs_request_id, doc_type, stored_filename, original_filename, mime_type, size_bytes) VALUES (?, 'prescription', ?, 'rx.pdf', 'application/pdf', 10)")->execute([$id, $stored]);
        $pdo = null;
        $res = $runWorker('erase', $id, $stored);
        assert_equal([$stored], $res['failed_files']);
        $page = http_request('GET', $env->baseUrl . '/admin/data-erasure/', ['cookie_jar' => $jar])['body'];
        assert_contains(">GS-{$id}</a>", $page);
        assert_contains("/admin/gs-requests/view.php?id={$id}", $page);
        $retry = $post(['action' => 'delete_leftovers']);
        assert_contains('Deleted', $retry['body']);
        clearstatcache();
        assert_true(!is_file($file));
    });

    run_test('the Privacy Policy says how long rejected applications are kept, and how to ask for removal', function () use ($env) {
        $policy = file_get_contents($env->root . '/public_html/Privacy Policy.html');
        assert_contains('<strong>Veterinary account applications.</strong>', $policy);
        assert_contains('If an application is rejected, we keep it for 12 months', $policy);
        assert_contains('the prescriber details on those requests are kept for as long as we are required to keep the request', $policy);
        $sample = file_get_contents($env->root . '/public_html/includes/db-config.sample.php');
        assert_contains("define('VET_APPLICATION_RETENTION_MONTHS', 12)", $sample, 'the setting is documented with the same default as the policy');
    });
};
