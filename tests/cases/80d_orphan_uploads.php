<?php
// Uploads are written to disk BEFORE the case / application row exists, and the form's own cleanup only covers errors PHP
// can catch: a request that dies (timeout, memory, restart) left its prescription on the server for ever, with nothing
// pointing at it. includes/orphan-uploads.php finds those and MOVES them to a holding folder (purged after 30 days). The
// rule that matters is "no row points at the file": relocate_uploaded_file() only logs a failure and download.php falls back
// to "pending", so a referenced file can legitimately stay there. These tests drive the admin button over HTTP and, for the
// automatic trigger, a real vet application (tests set ORPHAN_SWEEP_ONE_IN = 1, so every successful submission sweeps).
return function (TestEnv $env): void {
    $store = $env->storageDir;
    $name = fn (string $ext = 'pdf') => bin2hex(random_bytes(16)) . '.' . $ext;
    $put = function (string $rel, int $ageSeconds) use ($store): string {
        $path = $store . '/' . $rel;
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0777, true);
        file_put_contents($path, '%PDF-1.4 fake');
        touch($path, time() - $ageSeconds);
        return $path;
    };
    $held = fn (string $area, string $sub, string $file) => glob($store . "/orphaned/*/{$area}/{$sub}__{$file}") ?: [];
    $check = function () use ($env): array {
        $jar = $env->cookieJarStaff;
        $get = http_request('GET', $env->baseUrl . '/admin/data-erasure/', ['cookie_jar' => $jar]);
        $r = http_request('POST', $env->baseUrl . '/admin/data-erasure/', ['cookie_jar' => $jar, 'follow' => true,
            'body' => ['csrf_token' => extract_csrf($get['body']), 'action' => 'check_orphans']]);
        clearstatcache(); // answers about files must come from the disk, not from PHP's memory of an earlier check
        return $r;
    };
    $days = fn (int $n) => date('Y-m-d', time() - $n * 86400);
    $caseWithDoc = function (string $stored) use ($env): int {
        $pdo = $env->pdo();
        $pdo->prepare("INSERT INTO gs_requests (source, owner_full_name, owner_email, owner_phone, owner_address, owner_city, owner_state, owner_pin, patient_name, vet_name, vet_email, requested_formulation)
                       VALUES ('cat_owner','Orphan Test','orphan.test@example.test','9999999999','1 Road','Pune','MH','411001','Tom','Dr V','v@example.test','oral')")->execute();
        $id = (int) $env->scalar("SELECT id FROM gs_requests WHERE owner_email = 'orphan.test@example.test' ORDER BY id DESC LIMIT 1");
        $pdo->prepare("INSERT INTO gs_request_documents (gs_request_id, doc_type, stored_filename, original_filename, mime_type, size_bytes) VALUES (?, 'prescription', ?, 'rx.pdf', 'application/pdf', 13)")->execute([$id, $stored]);
        return $id;
    };

    run_test('an old file with no record is moved to the holding folder; the audit log lists it; the page says so', function () use ($env, $name, $put, $held, $check) {
        $n = $name();
        $path = $put("gs-requests/pending/{$n}", 2 * 86400);
        $r = $check();
        assert_true(!is_file($path), 'the orphan must leave the pending folder');
        assert_equal(1, count($held('gs-requests', 'pending', $n)), 'it must be in the holding folder, under its origin');
        assert_contains('unreferenced file(s) moved to the holding folder', $r['body']);
        $meta = (string) $env->scalar("SELECT metadata_json FROM audit_log WHERE action = 'orphan_uploads_held' ORDER BY id DESC LIMIT 1");
        assert_contains($n, $meta);
        assert_equal('staff', $env->scalar("SELECT actor_type FROM audit_log WHERE action = 'orphan_uploads_held' ORDER BY id DESC LIMIT 1"));
    });

    run_test('a file a case points at is NEVER touched, even in pending and even when old (the relocation may have failed)', function () use ($env, $name, $put, $caseWithDoc, $check) {
        $n = $name();
        $path = $put("gs-requests/pending/{$n}", 40 * 86400);
        $caseWithDoc($n);
        $r = $check();
        assert_true(is_file($path), 'a referenced prescription must stay where it is');
        assert_contains('left alone because a record uses them', $r['body']);
        assert_equal([], glob($env->storageDir . "/orphaned/*/gs-requests/pending__{$n}") ?: []);
    });

    run_test('a young file is left alone (a submission in progress has a file with no row for a moment)', function () use ($env, $name, $put, $check) {
        $n = $name();
        $path = $put("gs-requests/pending/{$n}", 600);
        $check();
        assert_true(is_file($path), 'a file under a day old must not be touched');
    });

    run_test('an orphan in a case folder (the request died after the move but before the commit) is found too, and the empty folder goes', function () use ($env, $name, $put, $held, $check) {
        $n = $name('png');
        $path = $put("gs-requests/987654/{$n}", 3 * 86400);
        $check();
        assert_true(!is_file($path));
        assert_equal(1, count($held('gs-requests', '987654', $n)));
        assert_true(!is_dir($env->storageDir . '/gs-requests/987654'), 'the emptied folder of a record that never existed is removed');
    });

    run_test('the vet-application area works the same way, and a referenced certificate stays', function () use ($env, $name, $put, $held, $check) {
        $orphan = $name();
        $kept = $name();
        $put("vet-applications/pending/{$orphan}", 2 * 86400);
        $keptPath = $put("vet-applications/pending/{$kept}", 2 * 86400);
        $appId = (int) $env->scalar('SELECT id FROM vet_applications ORDER BY id LIMIT 1');
        assert_true($appId > 0, 'earlier tests create vet applications');
        $env->pdo()->prepare("INSERT INTO vet_application_documents (application_id, stored_filename, original_filename, mime_type, size_bytes) VALUES (?, ?, 'cert.pdf', 'application/pdf', 13)")->execute([$appId, $kept]);
        $check();
        assert_equal(1, count($held('vet-applications', 'pending', $orphan)));
        assert_true(is_file($keptPath), 'a registration certificate with a record must stay');
    });

    run_test('files that are not ours are never touched: odd names, other folders, the holding folder itself', function () use ($env, $name, $put, $check) {
        $a = $put('gs-requests/pending/notes.txt', 9 * 86400);
        $b = $put('gs-requests/pending/ABCDEF.pdf', 9 * 86400);
        $c = $put('gs-requests/pending/' . strtoupper($name()), 9 * 86400); // upper-case hex is not what we write
        $d = $put('other-area/pending/' . $name(), 9 * 86400);
        $e = $put('gs-requests/not-a-case/' . $name(), 9 * 86400);
        $check();
        foreach ([$a, $b, $c, $d, $e] as $p) assert_true(is_file($p), 'must be left alone: ' . basename($p));
    });

    run_test('a file an erasure is still waiting to delete is left to the leftover-files panel', function () use ($env, $name, $put, $check) {
        $n = $name();
        $id = 880011;
        $path = $put("gs-requests/{$id}/{$n}", 5 * 86400);
        $env->pdo()->prepare("INSERT INTO audit_log (actor_type, action, entity_type, entity_id, metadata_json) VALUES ('staff','case_erased','gs_request',?,?)")
            ->execute([$id, json_encode(['files' => [$n]])]);
        $check();
        assert_true(is_file($path), 'the erasure panel owns this file; the sweep must not move it');
        $env->pdo()->prepare("DELETE FROM audit_log WHERE action = 'case_erased' AND entity_id = ?")->execute([$id]);
        @unlink($path);
        @rmdir($env->storageDir . "/gs-requests/{$id}");
    });

    run_test('holding folders older than 30 days are purged; younger ones and anything not date-named stay', function () use ($env, $name, $put, $days, $check) {
        $old = $put('orphaned/' . $days(40) . '/gs-requests/pending__' . $name(), 40 * 86400);
        $young = $put('orphaned/' . $days(5) . '/gs-requests/pending__' . $name(), 5 * 86400);
        $stray = $put('orphaned/keep-me/gs-requests/pending__' . $name(), 400 * 86400);
        $r = $check();
        assert_true(!is_file($old), 'a file held for 40 days is deleted');
        assert_true(!is_dir(dirname(dirname($old))), 'and its emptied day folder goes');
        assert_true(is_file($young), 'a file held for 5 days stays');
        assert_true(is_file($stray), 'a folder that is not a date is never touched');
        assert_contains('deleted from the holding folder after 30 days', $r['body']);
        assert_true((int) $env->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'orphan_uploads_purged'") >= 1);
    });

    run_test('the admin page shows the holding folder, and only an admin may run the check', function () use ($env) {
        $page = http_request('GET', $env->baseUrl . '/admin/data-erasure/', ['cookie_jar' => $env->cookieJarStaff])['body'];
        assert_contains('Uploads no record points at', $page);
        assert_contains('Check for unreferenced uploads now', $page);
        assert_true((bool) preg_match('~\d+ file\(s\) are in the holding folder~', $page), 'the page says how many files are being held');
        $anon = http_request('POST', $env->baseUrl . '/admin/data-erasure/', ['cookie_jar' => $env->tmpDir . '/cookies-orphan-anon.txt', 'body' => ['action' => 'check_orphans']]);
        assert_equal(302, $anon['status'], 'a visitor who is not signed in is sent to the sign-in page');
    });

    run_test('a successful vet application sweeps by itself (here: every one); a refused submission does not', function () use ($env, $name, $put) {
        $env->pdo()->exec("DELETE FROM rate_limit_hits WHERE bucket LIKE 'vet_apply_submit:%'");
        $n1 = $name();
        $p1 = $put("vet-applications/pending/{$n1}", 3 * 86400);
        $jar = $env->tmpDir . '/cookies-orphan-apply.txt';
        $get = http_request('GET', $env->baseUrl . '/for-veterinarians/apply/index.php', ['cookie_jar' => $jar]);
        $bad = http_request('POST', $env->baseUrl . '/for-veterinarians/apply/index.php', ['cookie_jar' => $jar, 'body' => ['csrf_token' => extract_csrf($get['body']), 'ott' => extract_ott($get['body']), 'professional_email' => 'nope']]);
        assert_equal(200, $bad['status']);
        assert_true(is_file($p1), 'a submission that was refused for a field error does not trigger the sweep');

        $get = http_request('GET', $env->baseUrl . '/for-veterinarians/apply/index.php', ['cookie_jar' => $jar]);
        $ok = http_request('POST', $env->baseUrl . '/for-veterinarians/apply/index.php', ['cookie_jar' => $jar, 'body' => [
            'csrf_token' => extract_csrf($get['body']), 'ott' => extract_ott($get['body']),
            'full_name' => 'Dr. Sweep Test', 'professional_email' => 'sweep.test@example.test', 'mobile' => '9000000001',
            'registration_number' => 'MH-VET-5555', 'registration_state' => 'Maharashtra', 'registration_country' => 'India',
            'qualification' => 'BVSc', 'year_qualified' => '2016', 'practice_type' => 'Independent practice',
            'clinic_name' => 'Sweep Clinic', 'clinic_address' => '3 Road', 'clinic_city' => 'Pune', 'clinic_state' => 'Maharashtra',
            'clinic_pin' => '411004', 'clinic_country' => 'India', 'clinic_phone' => '02011111111', 'consent' => '1',
            'registration_certificate' => new CURLFile(__DIR__ . '/../fixtures/tiny.png', 'image/png', 'cert.png')]]);
        assert_equal(302, $ok['status'], 'the application itself must go through');
        clearstatcache(); // is_file($p1) answered "yes" a moment ago and PHP remembers it
        assert_true(!is_file($p1), 'the successful submission swept the old orphan');
        $env->pdo()->exec("DELETE FROM rate_limit_hits WHERE bucket LIKE 'vet_apply_submit:%'");
    });
};
