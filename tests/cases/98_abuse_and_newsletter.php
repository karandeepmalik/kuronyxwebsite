<?php
// Covers: login lockout keyed on account+IP (so a stranger can't lock the real owner out),
// None of these let
// a request reach the real Brevo API — the test server has no curl extension, so any
// attempt to call it would fatal and fail the test instead of silently passing.
return function (TestEnv $env): void {
    $clearIpBuckets = function () use ($env) {
        $env->pdo()->exec("DELETE FROM rate_limit_hits WHERE bucket LIKE 'login_ip:%' OR bucket LIKE 'send_welcome:%' OR bucket LIKE 'newsletter_%' OR bucket LIKE 'vet_apply_submit:%'");
    };

    run_test('failed logins from a different IP do not lock the real owner out; the same IP does', function () use ($env, $clearIpBuckets) {
        $email = 'victim-admin@example.test';
        $pdo = $env->pdo();
        $ins = $pdo->prepare('INSERT INTO login_attempts (identifier, succeeded) VALUES (?, 0)');
        for ($i = 0; $i < 8; $i++) {
            $ins->execute([$email . '|203.0.113.9']); // an attacker elsewhere burned 8 attempts
        }
        $pdo = null; $ins = null;

        $jar = $env->tmpDir . '/cookies-lockout-ip.txt';
        $attempt = function () use ($env, $jar, $email) {
            $get = http_request('GET', $env->baseUrl . '/admin/login.php', ['cookie_jar' => $jar]);
            return http_request('POST', $env->baseUrl . '/admin/login.php', [
                'cookie_jar' => $jar,
                'body' => ['csrf_token' => extract_csrf($get['body']), 'email' => $email, 'password' => 'wrong-password'],
            ]);
        };
        $r = $attempt();
        assert_contains('Invalid email or password', $r['body'], 'an attacker on another IP must not be able to lock this visitor out');
        assert_true(strpos($r['body'], 'Too many failed attempts') === false);

        // The same IP burning through its own 8 attempts is still locked out, as before.
        for ($i = 0; $i < 8; $i++) { $attempt(); }
        $locked = $attempt();
        assert_contains('Too many failed attempts', $locked['body']);

        $env->pdo()->exec("DELETE FROM login_attempts WHERE identifier LIKE 'victim-admin@example.test|%'");
        $clearIpBuckets();
    });

    run_test('clear_login_attempts() removes every IP\'s rows for one account and nobody else\'s', function () use ($env) {
        require_once $env->root . '/public_html/includes/auth.php';
        $pdo = $env->pdo();
        $ins = $pdo->prepare('INSERT INTO login_attempts (identifier, succeeded) VALUES (?, 0)');
        foreach (['clear-me@example.test|1.1.1.1', 'clear-me@example.test|2.2.2.2', 'clear-me@example.test', 'clear-me@example.test.evil|3.3.3.3', 'keep-me@example.test|1.1.1.1'] as $id) {
            $ins->execute([$id]);
        }
        $pdo = null; $ins = null;
        clear_login_attempts('clear-me@example.test');
        $left = $env->pdo()->query("SELECT identifier FROM login_attempts WHERE identifier LIKE 'clear-me%' OR identifier LIKE 'keep-me%' ORDER BY identifier")->fetchAll(PDO::FETCH_COLUMN);
        assert_equal(['clear-me@example.test.evil|3.3.3.3', 'keep-me@example.test|1.1.1.1'], $left);
        $env->pdo()->exec("DELETE FROM login_attempts WHERE identifier LIKE 'clear-me%' OR identifier LIKE 'keep-me%'");
    });
};
