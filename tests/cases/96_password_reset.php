<?php
// Self-service password reset for staff/admin (staff_users) and veterinarians
// (vet_accounts). Runs last on purpose: it changes real seeded accounts' passwords.
return function (TestEnv $env): void {
    $countEmailsTo = function (string $to) use ($env): int {
        if (!is_file($env->dryRunEmailsPath)) return 0;
        $n = 0;
        foreach (array_filter(explode("\n", file_get_contents($env->dryRunEmailsPath))) as $line) {
            $e = json_decode($line, true);
            if ($e && $e['to'] === $to) $n++;
        }
        return $n;
    };

    $requestReset = function (string $path, string $email, string $jarName) use ($env) {
        $jar = $env->tmpDir . "/{$jarName}.txt";
        $get = http_request('GET', $env->baseUrl . $path, ['cookie_jar' => $jar]);
        $csrf = extract_csrf($get['body']);
        return http_request('POST', $env->baseUrl . $path, ['cookie_jar' => $jar, 'body' => ['csrf_token' => $csrf, 'email' => $email]]);
    };

    $login = function (string $loginPath, string $email, string $password, string $jarName) use ($env) {
        $jar = $env->tmpDir . "/{$jarName}.txt";
        $get = http_request('GET', $env->baseUrl . $loginPath, ['cookie_jar' => $jar]);
        $csrf = extract_csrf($get['body']);
        return http_request('POST', $env->baseUrl . $loginPath, ['cookie_jar' => $jar, 'body' => ['csrf_token' => $csrf, 'email' => $email, 'password' => $password]]);
    };

    $setPassword = function (string $resetPath, string $token, string $password, string $confirm, string $jarName) use ($env) {
        $jar = $env->tmpDir . "/{$jarName}.txt";
        $get = http_request('GET', $env->baseUrl . $resetPath . '?token=' . $token, ['cookie_jar' => $jar]);
        $csrf = extract_csrf($get['body']);
        return http_request('POST', $env->baseUrl . $resetPath, ['cookie_jar' => $jar, 'body' => [
            'csrf_token' => $csrf, 'token' => $token, 'password' => $password, 'password_confirm' => $confirm,
        ]]);
    };

    run_test('both login pages link to a forgot-password page', function () use ($env) {
        $staff = http_request('GET', $env->baseUrl . '/admin/login.php');
        assert_contains('/admin/forgot-password.php', $staff['body']);
        $vet = http_request('GET', $env->baseUrl . '/for-veterinarians/login.php');
        assert_contains('/for-veterinarians/forgot-password.php', $vet['body']);
    });

    run_test('requesting a reset for an unknown email looks identical and sends nothing', function () use ($env, $requestReset, $countEmailsTo) {
        $r = $requestReset('/admin/forgot-password.php', 'nobody@example.test', 'cookies-pwreset-unknown');
        assert_equal(200, $r['status']);
        assert_contains('If a staff account exists', $r['body']);
        assert_equal(0, $countEmailsTo('nobody@example.test'));
    });

    run_test('a staff reset email is sent, the link sets a new password, and the old one stops working', function () use ($env, $requestReset, $countEmailsTo, $setPassword, $login) {
        $r = $requestReset('/admin/forgot-password.php', 'staff@example.test', 'cookies-pwreset-staff');
        assert_contains('If a staff account exists', $r['body']);

        $email = $env->lastDryRunEmailTo('staff@example.test');
        assert_true($email !== null, 'a reset email should have been sent to the staff account');
        assert_true((bool) preg_match('#/admin/reset-password\.php\?token=([a-f0-9]+)#', $email['body'], $m), 'reset link missing from the email');
        $token = $m[1];
        $env->shared['staffResetToken'] = $token;

        $short = $setPassword('/admin/reset-password.php', $token, 'short', 'short', 'cookies-pwreset-staff-set');
        assert_contains('has-error', $short['body'], 'a too-short password should be rejected');

        $mismatch = $setPassword('/admin/reset-password.php', $token, 'a-brand-new-password-1', 'a-different-password-2', 'cookies-pwreset-staff-set2');
        assert_contains('has-error', $mismatch['body'], 'mismatched confirmation should be rejected');

        $ok = $setPassword('/admin/reset-password.php', $token, 'a-brand-new-password-1', 'a-brand-new-password-1', 'cookies-pwreset-staff-set3');
        assert_contains('Your password has been changed', $ok['body']);

        $newLogin = $login('/admin/login.php', 'staff@example.test', 'a-brand-new-password-1', 'cookies-pwreset-staff-login-new');
        assert_equal(302, $newLogin['status'], 'the new password should sign in');
        $oldLogin = $login('/admin/login.php', 'staff@example.test', 'another-strong-pass', 'cookies-pwreset-staff-login-old');
        assert_contains('Invalid email or password', $oldLogin['body']);
    });

    run_test('a reset link can only be used once', function () use ($env, $login) {
        $token = $env->shared['staffResetToken'];
        $page = http_request('GET', $env->baseUrl . '/admin/reset-password.php?token=' . $token);
        assert_contains('invalid or has expired', $page['body']);

        // The spent link shows no form, so a real attacker would POST it directly with a
        // valid session's CSRF token taken from some other page.
        $jar = $env->tmpDir . '/cookies-pwreset-staff-reuse.txt';
        $csrf = extract_csrf(http_request('GET', $env->baseUrl . '/admin/forgot-password.php', ['cookie_jar' => $jar])['body']);
        $post = http_request('POST', $env->baseUrl . '/admin/reset-password.php', ['cookie_jar' => $jar, 'body' => [
            'csrf_token' => $csrf, 'token' => $token, 'password' => 'yet-another-password-3', 'password_confirm' => 'yet-another-password-3',
        ]]);
        assert_contains('invalid or has expired', $post['body']);

        $attempt = $login('/admin/login.php', 'staff@example.test', 'yet-another-password-3', 'cookies-pwreset-staff-reuse-login');
        assert_contains('Invalid email or password', $attempt['body'], 'the spent link must not have changed the password');
    });

    run_test('an expired reset link is rejected', function () use ($env, $requestReset) {
        $requestReset('/admin/forgot-password.php', 'staff@example.test', 'cookies-pwreset-staff-expiry');
        $email = $env->lastDryRunEmailTo('staff@example.test');
        preg_match('#reset-password\.php\?token=([a-f0-9]+)#', $email['body'], $m);
        (function () use ($env) {
            $env->pdo()->prepare("UPDATE staff_users SET password_reset_expires_at = ? WHERE email = 'staff@example.test'")
                ->execute([gmdate('Y-m-d H:i:s', time() - 60)]);
        })();
        $r = http_request('GET', $env->baseUrl . '/admin/reset-password.php?token=' . $m[1]);
        assert_contains('invalid or has expired', $r['body']);
    });

    run_test('a garbage token is rejected', function () use ($env) {
        $r = http_request('GET', $env->baseUrl . '/admin/reset-password.php?token=not-a-real-token');
        assert_contains('invalid or has expired', $r['body']);
        $none = http_request('GET', $env->baseUrl . '/admin/reset-password.php');
        assert_contains('invalid or has expired', $none['body']);
    });

    run_test('the reset link is built from configuration, not the request Host header', function () use ($env, $countEmailsTo) {
        $jar = $env->tmpDir . '/cookies-pwreset-hostheader.txt';
        $get = http_request('GET', $env->baseUrl . '/admin/forgot-password.php', ['cookie_jar' => $jar]);
        $csrf = extract_csrf($get['body']);
        http_request('POST', $env->baseUrl . '/admin/forgot-password.php', [
            'cookie_jar' => $jar,
            'headers' => ['Host: evil.example'],
            'body' => ['csrf_token' => $csrf, 'email' => 'staff@example.test'],
        ]);
        $email = $env->lastDryRunEmailTo('staff@example.test');
        assert_true(strpos($email['body'], 'evil.example') === false, 'a forged Host header must not end up in the emailed link');
        assert_contains('https://kuronyx.in/admin/reset-password.php', $email['body']);
    });

    run_test('a veterinarian can reset their portal password the same way', function () use ($env, $requestReset, $setPassword, $login) {
        $r = $requestReset('/for-veterinarians/forgot-password.php', 'portal.vet@example.test', 'cookies-pwreset-vet');
        assert_contains('If a veterinary portal account exists', $r['body']);
        $email = $env->lastDryRunEmailTo('portal.vet@example.test');
        assert_true((bool) preg_match('#/for-veterinarians/reset-password\.php\?token=([a-f0-9]+)#', $email['body'], $m), 'vet reset link missing');

        $ok = $setPassword('/for-veterinarians/reset-password.php', $m[1], 'a-new-vet-password-1', 'a-new-vet-password-1', 'cookies-pwreset-vet-set');
        assert_contains('Your password has been changed', $ok['body']);

        $newLogin = $login('/for-veterinarians/login.php', 'portal.vet@example.test', 'a-new-vet-password-1', 'cookies-pwreset-vet-login');
        assert_equal(302, $newLogin['status']);
        $oldLogin = $login('/for-veterinarians/login.php', 'portal.vet@example.test', 'reactivated-password-1', 'cookies-pwreset-vet-login-old');
        assert_contains('Invalid email or password', $oldLogin['body']);
    });

    run_test('a suspended vet account gets no reset email (and the response does not reveal that)', function () use ($env, $requestReset, $countEmailsTo) {
        (function () use ($env) {
            $env->pdo()->exec("UPDATE vet_accounts SET status = 'suspended' WHERE email = 'portal.vet@example.test'");
        })();
        $before = $countEmailsTo('portal.vet@example.test');
        $r = $requestReset('/for-veterinarians/forgot-password.php', 'portal.vet@example.test', 'cookies-pwreset-vet-suspended');
        assert_contains('If a veterinary portal account exists', $r['body']);
        assert_equal($before, $countEmailsTo('portal.vet@example.test'));
        (function () use ($env) {
            $env->pdo()->exec("UPDATE vet_accounts SET status = 'active' WHERE email = 'portal.vet@example.test'");
        })();
    });

    run_test('reset emails to one address are capped so the form cannot be used to flood an inbox', function () use ($env, $requestReset, $countEmailsTo) {
        for ($i = 0; $i < 4; $i++) {
            $r = $requestReset('/admin/forgot-password.php', 'admin@example.test', "cookies-pwreset-cap-{$i}");
            assert_contains('If a staff account exists', $r['body'], 'every attempt gets the same response, even once capped');
        }
        assert_equal(3, $countEmailsTo('admin@example.test'), 'only 3 reset emails per address per hour should actually be sent');
    });
};
