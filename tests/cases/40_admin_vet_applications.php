<?php
return function (TestEnv $env): void {
    run_test('admin can list vet applications', function () use ($env) {
        $r = http_request('GET', $env->baseUrl . '/admin/veterinary-applications/', ['cookie_jar' => $env->cookieJarStaff]);
        assert_equal(200, $r['status']);
        assert_contains('Asha Verma', $r['body']);
    });

    run_test('admin can approve a vet application', function () use ($env) {
        $id = (int) $env->pdo()->query("SELECT id FROM vet_applications WHERE professional_email = 'asha.verma@example.test'")->fetchColumn();
        assert_true($id > 0, 'expected the vet application from the public-forms test');
        $jar = $env->cookieJarStaff;

        $view = http_request('GET', $env->baseUrl . "/admin/veterinary-applications/view.php?id={$id}", ['cookie_jar' => $jar]);
        $csrf = extract_csrf($view['body']);
        $post = http_request('POST', $env->baseUrl . "/admin/veterinary-applications/view.php?id={$id}", [
            'cookie_jar' => $jar,
            'body' => ['csrf_token' => $csrf, 'action' => 'approve', 'note' => 'Registration verified by phone.'],
        ]);
        assert_contains('Application approved', $post['body']);
        assert_equal('approved', $env->pdo()->query("SELECT status FROM vet_applications WHERE id = {$id}")->fetchColumn());
        $env->shared['vetApplicationId'] = $id;
    });

    run_test('adding a note appends to internal_notes via the database, not a stale in-PHP snapshot', function () use ($env) {
        // Regression test for the internal_notes lost-write race: applyNote() used to read
        // internal_notes into PHP at the top of the request, append in PHP, and write the
        // whole thing back — so a concurrent change to the row (simulated here via a raw
        // SQL write between this test's GET and its POST, standing in for a second staff
        // member's action landing in between) would be silently discarded once this
        // request's own stale read overwrote it. The fix appends via the database's own
        // CONCAT/|| inside the UPDATE itself, so it can't lose a write no matter what
        // changed the row in between.
        $id = $env->shared['vetApplicationId'];
        $jar = $env->cookieJarStaff;
        $view = http_request('GET', $env->baseUrl . "/admin/veterinary-applications/view.php?id={$id}", ['cookie_jar' => $jar]);
        $csrf = extract_csrf($view['body']);

        // String concatenation differs by engine: || is concatenation in SQLite but logical OR in MySQL.
        $concat = $env->isMysql ? "CONCAT(COALESCE(internal_notes, ''), ?)" : "COALESCE(internal_notes, '') || ?";
        $env->pdo()->prepare("UPDATE vet_applications SET internal_notes = {$concat} WHERE id = ?")
            ->execute(["\n[concurrent] Someone else's note landed first.", $id]);

        $post = http_request('POST', $env->baseUrl . "/admin/veterinary-applications/view.php?id={$id}", [
            'cookie_jar' => $jar,
            'body' => ['csrf_token' => $csrf, 'action' => 'add_note', 'note' => 'This note must not erase the concurrent one above it.'],
        ]);
        assert_contains('Note added', $post['body']);

        $notes = (string) $env->scalar('SELECT internal_notes FROM vet_applications WHERE id = ?', [$id]);
        assert_contains("Someone else's note landed first", $notes, 'the concurrently-written note must still be present');
        assert_contains('This note must not erase the concurrent one above it', $notes, 'this request\'s own note must also be present');
    });

    run_test('pharmacy_staff can review applications but is blocked from the admin-only audit log', function () use ($env) {
        // Seed a pharmacy_staff account directly — there is no self-serve staff
        // creation yet (that's part of a later build phase). Use a throwaway PDO
        // handle that goes out of scope immediately: holding one open in a local
        // var across the http_request() calls below can stall the separate
        // `php -S` process's writes to the same SQLite file on Windows.
        (function () use ($env) {
            $pdo = $env->pdo();
            $pdo->prepare("INSERT INTO staff_users (name, email, password_hash, role) VALUES (?, ?, ?, 'pharmacy_staff')")
                ->execute(['Staff Member', 'staff@example.test', password_hash('another-strong-pass', PASSWORD_DEFAULT)]);
        })();

        $jar = $env->tmpDir . '/cookies-pharmstaff.txt';
        $get = http_request('GET', $env->baseUrl . '/admin/login.php', ['cookie_jar' => $jar]);
        $csrf = extract_csrf($get['body']);
        http_request('POST', $env->baseUrl . '/admin/login.php', [
            'cookie_jar' => $jar,
            'body' => ['csrf_token' => $csrf, 'email' => 'staff@example.test', 'password' => 'another-strong-pass'],
        ]);

        $id = $env->shared['vetApplicationId'];
        $view = http_request('GET', $env->baseUrl . "/admin/veterinary-applications/view.php?id={$id}", ['cookie_jar' => $jar]);
        assert_equal(200, $view['status']);

        $auditR = http_request('GET', $env->baseUrl . '/admin/audit-log/', ['cookie_jar' => $jar]);
        assert_equal(403, $auditR['status']);
    });
};
