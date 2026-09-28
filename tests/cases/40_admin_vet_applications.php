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
