<?php
// is_unique_violation() (includes/auth.php) replaced bare SQLSTATE 23000 checks. 23000 is the generic
// code for ANY integrity error - duplicate key, foreign key, NOT NULL - so "23000 means my duplicate"
// retried or reported the wrong thing for the others. This runs real violations of each kind against
// the real tables of whichever engine the suite is on (SQLite by default; MariaDB/MySQL with
// KURONYX_TEST_MYSQL) and checks the helper tells them apart from each engine's own wording.
return function (TestEnv $env): void {
    $runWorker = function () use ($env): array {
        $pipes = [];
        $proc = proc_open(
            ['php', '-c', $env->iniPath, $env->root . '/tests/lib/unique_violation_worker.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        fclose($pipes[0]);
        $text = trim(stream_get_contents($pipes[1]));
        $log  = trim(stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        $data = json_decode($text, true);
        assert_true(is_array($data), "the worker did not return JSON: {$text} {$log}");
        assert_true(!isset($data['error']), 'the worker failed: ' . ($data['error'] ?? ''));
        return $data;
    };

    run_test('is_unique_violation() tells a duplicate key from a foreign-key or NOT NULL error, on this engine\'s own messages', function () use ($env, $runWorker) {
        $r = $runWorker();
        assert_equal($env->isMysql ? 'mysql' : 'sqlite', $r['engine']);
        $msgs = json_encode($r['messages'], JSON_UNESCAPED_SLASHES);
        $v = fn (string $case) => $r['cases'][$case]['verdicts'] ?? null;

        // Every provocation must really have been refused (a "NO ERROR" string would not have 'verdicts').
        foreach ($r['cases'] as $name => $case) {
            assert_true(is_array($case), "{$name}: {$case}");
        }
        // All of them are the same SQLSTATE - that is the whole problem with checking 23000.
        foreach ($r['cases'] as $name => $case) {
            assert_equal('23000', $case['sqlstate'], "{$name} should be SQLSTATE 23000 on this engine ({$msgs})");
        }

        assert_equal(['dispatches.slug' => true, 'dispatches.title' => false, 'staff_users.email' => false], $v('dispatch duplicate slug'), "a duplicate slug is a slug duplicate, and only that ({$msgs})");
        assert_equal(['dispatches.slug' => false], $v('dispatch foreign key'), "a foreign-key error is not a duplicate slug ({$msgs})");
        assert_equal(['dispatches.slug' => false], $v('dispatch NOT NULL'), "a NOT NULL error is not a duplicate slug ({$msgs})");
        assert_equal(['staff_users.email' => true, 'staff_users.name' => false], $v('staff duplicate email'), "a duplicate staff email ({$msgs})");

        // vet_accounts has two unique columns that mean different things.
        assert_equal(['vet_accounts.vet_application_id' => false, 'vet_accounts.email' => true], $v('vet account same email, different application'), "same email is an EMAIL duplicate ({$msgs})");
        assert_equal(['vet_accounts.vet_application_id' => true, 'vet_accounts.email' => false], $v('vet account same application, different email'), "same application is an APPLICATION duplicate ({$msgs})");

        // The PRIMARY KEY of setup_lock: MySQL calls that key 'PRIMARY' whatever the column is named.
        assert_equal(['setup_lock.id' => true, 'setup_lock.nope' => $env->isMysql ? true : false], $v('setup_lock primary key'), "the setup_lock primary key ({$msgs})");
    });

    // ---- Approving a vet application: which unique column collided decides what staff are told ----
    // The pre-check in view.php handles "an account already exists for this email" before the INSERT; what
    // is left is a RACE, where another request creates a conflicting account between that check and the
    // INSERT. A SQLite trigger that inserts the rival row just before the INSERT reproduces it exactly
    // (a trigger can't modify its own table on MySQL, hence SQLite-only).
    $approveWithRival = function (string $rivalSql, string $emailTag) use ($env): array {
        $email = "race-{$emailTag}@example.test";
        $ids = (function () use ($env, $email) {
            $pdo = $env->pdo();
            $ins = $pdo->prepare("INSERT INTO vet_applications (full_name, professional_email, mobile, registration_number, registration_state, qualification, year_qualified, practice_type, clinic_name, clinic_address, clinic_city, clinic_state, clinic_pin, clinic_phone)
                VALUES ('Race Vet', ?, '9000000000', 'RACE', 'MH', 'BVSc', 2020, 'x', 'Race Clinic', 'x', 'x', 'x', '000000', '9000000000')");
            $ins->execute([$email]);
            $target = (int) $pdo->lastInsertId();
            $ins->execute(["rival-{$email}"]);
            return [$target, (int) $pdo->lastInsertId()];
        })();
        [$target, $rival] = $ids;

        $jar = $env->cookieJarStaff;
        $view = http_request('GET', $env->baseUrl . "/admin/veterinary-applications/view.php?id={$target}", ['cookie_jar' => $jar]);
        (function () use ($env, $rivalSql, $target, $rival, $email) {
            $sql = strtr($rivalSql, [':target' => (string) $target, ':rival' => (string) $rival, ':email' => $email]);
            $env->pdo()->exec("CREATE TRIGGER trg_test_vet_race BEFORE INSERT ON vet_accounts WHEN NEW.vet_application_id = {$target} BEGIN {$sql} END");
        })();
        try {
            $post = http_request('POST', $env->baseUrl . "/admin/veterinary-applications/view.php?id={$target}", ['follow' => true, 
                'cookie_jar' => $jar, 'body' => ['csrf_token' => extract_csrf($view['body']), 'expected_status' => extract_expected_status($view['body']), 'action' => 'approve', 'note' => 'Verified in test.'],
            ]);
        } finally {
            (function () use ($env) { $env->pdo()->exec('DROP TRIGGER IF EXISTS trg_test_vet_race'); })();
        }
        return ['post' => $post, 'target' => $target, 'rival' => $rival, 'email' => $email];
    };
    $cleanVet = function (array $r) use ($env) {
        $pdo = $env->pdo();
        $pdo->exec('DELETE FROM vet_accounts WHERE vet_application_id IN (' . (int) $r['target'] . ',' . (int) $r['rival'] . ')');
        $pdo->exec('DELETE FROM vet_applications WHERE id IN (' . (int) $r['target'] . ',' . (int) $r['rival'] . ')');
    };

    run_test('approving when another request just made an account for the SAME APPLICATION says so', function () use ($env, $approveWithRival, $cleanVet) {
        if ($env->isMysql) skip_test('SQLite-only: the trigger that creates the rival account');
        $r = $approveWithRival("INSERT INTO vet_accounts (vet_application_id, email, status) VALUES (:target, 'other-:email', 'pending_activation');", 'sameapp');
        assert_contains('was just created by another request', $r['post']['body']);
        assert_equal('approved', $env->scalar('SELECT status FROM vet_applications WHERE id = ?', [$r['target']]));
        // (No row-count check here: in a real race the other request's committed account would exist, but
        // SQLite undoes a trigger's own insert when the statement that fired it fails, so this simulation
        // can only check the message and the outcome, not the rival row.)
        $cleanVet($r);
    });

    run_test('approving when another request just made an account for the SAME EMAIL says that, not "created by another request"', function () use ($env, $approveWithRival, $cleanVet) {
        if ($env->isMysql) skip_test('SQLite-only: the trigger that creates the rival account');
        $r = $approveWithRival("INSERT INTO vet_accounts (vet_application_id, email, status) VALUES (:rival, ':email', 'pending_activation');", 'sameemail');
        $body = $r['post']['body'];
        // A WARNING (amber), not a green success: no account was made for this application, so staff must follow up.
        // (In a real race the other request's account exists and the message names its application, as the non-race test in
        // 69e_vet_no_account_warning.php shows. SQLite undoes the trigger's own insert when the statement that fired it fails,
        // so here there is no owner left to name and it says "another application".)
        assert_contains('no portal account was created: an account for this email already belongs to another application.', $body);
        assert_true(strpos($body, 'class="alert alert-warn"') !== false && strpos($body, 'class="alert alert-ok"') === false, 'shown as a warning, not as success');
        assert_true(strpos($body, 'was just created by another request') === false, 'the other (application) message would be wrong here: no account was made for this application');
        assert_equal('approved', $env->scalar('SELECT status FROM vet_applications WHERE id = ?', [$r['target']]));
        assert_equal(0, (int) $env->scalar('SELECT COUNT(*) FROM vet_accounts WHERE vet_application_id = ?', [$r['target']]), 'no account was created for this application');
        assert_equal(1, (int) $env->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'vet_account_conflict' AND entity_id = ?", [$r['target']]), 'the conflict is audited, as in the non-race path');
        assert_contains('already exists for this email address', (string) $env->scalar('SELECT internal_notes FROM vet_applications WHERE id = ?', [$r['target']]));
        $cleanVet($r);
    });

    run_test('approving when the INSERT fails for any OTHER integrity reason is not mislabelled: it errors and nothing is approved', function () use ($env, $approveWithRival, $cleanVet) {
        if ($env->isMysql) skip_test('SQLite-only: the trigger that raises a non-duplicate constraint error');
        // RAISE(ABORT) is a constraint error with the same SQLSTATE 23000 but not a duplicate key.
        $r = $approveWithRival("SELECT RAISE(ABORT, 'some other integrity rule');", 'other');
        assert_equal(500, $r['post']['status'], 'an unexpected integrity error must surface, not be reported as a duplicate');
        assert_equal('pending', $env->scalar('SELECT status FROM vet_applications WHERE id = ?', [$r['target']]), 'and the whole approval rolled back');
        assert_equal(0, (int) $env->scalar('SELECT COUNT(*) FROM vet_accounts WHERE vet_application_id = ?', [$r['target']]));
        $cleanVet($r);
    });

    run_test('no bare SQLSTATE 23000 check is left in the app: every caller names the column it expects to collide', function () use ($env) {
        $offenders = [];
        $roots = [$env->root . '/public_html', $env->root . '/tools'];
        foreach ($roots as $root) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file->getExtension() !== 'php') continue;
                foreach (file($file->getPathname()) as $i => $line) {
                    // A code comparison like  getCode() === '23000'  (comments that mention it are fine).
                    if (preg_match("/getCode\(\)\s*[!=]==?\s*'23000'/", $line)) {
                        $offenders[] = str_replace($env->root . DIRECTORY_SEPARATOR, '', $file->getPathname()) . ':' . ($i + 1);
                    }
                }
            }
        }
        assert_equal([], $offenders, "these still treat any integrity error as a duplicate:\n  - " . implode("\n  - ", $offenders));
    });
};
