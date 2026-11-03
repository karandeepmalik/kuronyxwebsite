<?php
// New staff accounts are created by INVITATION: the admin enters name, email and role, and the new person is
// emailed a single-use link to set their own password. The admin never chooses, sees or passes on a password
// (they used to type one - and so knew it, and probably sent it over WhatsApp). Same link machinery as
// "Forgot your password?", with a 7-day life instead of 60 minutes.
return function (TestEnv $env): void {
    $jar = $env->cookieJarStaff;

    $emailsTo = function (string $to) use ($env): array {
        $found = [];
        if (!is_file($env->dryRunEmailsPath)) return $found;
        foreach (array_filter(explode("\n", file_get_contents($env->dryRunEmailsPath))) as $line) {
            $e = json_decode($line, true);
            if ($e && strcasecmp($e['to'], $to) === 0) $found[] = $e;
        }
        return $found;
    };

    // Opens the form and submits it. $extra is merged into the POST (e.g. a password the form should ignore).
    $invite = function (string $name, string $email, string $role = 'pharmacy_staff', array $extra = []) use ($env, $jar) {
        $get = http_request('GET', $env->baseUrl . '/admin/staff/new.php', ['cookie_jar' => $jar]);
        return http_request('POST', $env->baseUrl . '/admin/staff/new.php', ['cookie_jar' => $jar, 'body' => array_merge([
            'csrf_token' => extract_csrf($get['body']), 'ott' => extract_ott($get['body']),
            'name' => $name, 'email' => $email, 'role' => $role,
        ], $extra)]);
    };
    $linkIn = function (array $email): string {
        assert_true((bool) preg_match('#/admin/reset-password\.php\?token=([a-f0-9]{64})#', $email['body'], $m), 'the invitation has no set-password link');
        return $m[1];
    };
    $signIn = function (string $email, string $password, string $jarName) use ($env) {
        $j = $env->tmpDir . "/{$jarName}.txt";
        $get = http_request('GET', $env->baseUrl . '/admin/login.php', ['cookie_jar' => $j]);
        return http_request('POST', $env->baseUrl . '/admin/login.php', ['cookie_jar' => $j, 'body' => ['csrf_token' => extract_csrf($get['body']), 'email' => $email, 'password' => $password]]);
    };
    $setPassword = function (string $token, string $password, string $jarName) use ($env) {
        $j = $env->tmpDir . "/{$jarName}.txt";
        $get = http_request('GET', $env->baseUrl . '/admin/reset-password.php?token=' . $token, ['cookie_jar' => $j]);
        return http_request('POST', $env->baseUrl . '/admin/reset-password.php', ['cookie_jar' => $j, 'body' => [
            'csrf_token' => extract_csrf($get['body']), 'token' => $token, 'password' => $password, 'password_confirm' => $password,
        ]]);
    };

    run_test('the new-staff form has no password fields; it explains the invitation and carries a single-use token', function () use ($env, $jar) {
        $page = http_request('GET', $env->baseUrl . '/admin/staff/new.php', ['cookie_jar' => $jar])['body'];
        assert_true(strpos($page, 'type="password"') === false, 'the admin must not be asked for a password');
        assert_true(strpos($page, 'name="password') === false, 'no password input of any kind');
        assert_contains('You don\'t set a password', $page);
        assert_contains('valid for 7 days', $page);
        assert_contains('Create Account and Send Invitation', $page);
        assert_contains('name="ott"', $page);
    });

    run_test('creating an account emails the invitation to the new person only, with a 7-day single-use link, and the admin sees no link or password', function () use ($env, $jar, $invite, $emailsTo, $linkIn) {
        $r = $invite('Invited Person', 'invited.person@example.test');
        assert_equal(302, $r['status']);
        assert_contains('/admin/staff/', $r['location'] ?? '');

        // The result is shown once on the list, and says where the invitation went.
        $list = http_request('GET', $env->baseUrl . '/admin/staff/', ['cookie_jar' => $jar]);
        assert_contains('Staff account created for Invited Person. We emailed an invitation to invited.person@example.test', $list['body']);
        assert_contains('You never see their password', $list['body']);
        assert_true(strpos($list['body'], 'reset-password.php?token=') === false, 'the admin must never be shown the link');
        $again = http_request('GET', $env->baseUrl . '/admin/staff/', ['cookie_jar' => $jar]);
        assert_true(strpos($again['body'], 'We emailed an invitation') === false, 'the message is shown once');

        $emails = $emailsTo('invited.person@example.test');
        assert_equal(1, count($emails), 'exactly one invitation');
        $mail = $emails[0];
        assert_equal('Your Kuronyx staff account', $mail['subject']);
        assert_contains('Invited Person', $mail['body']);
        assert_contains('pharmacy staff', $mail['body']);
        assert_contains('valid for 7 days', $mail['body']);
        assert_contains('Forgot your password?', $mail['body'], 'the email says what to do if the link expires');
        $token = $linkIn($mail);

        // The account: active, right role, a stored hash of the token (never the raw token), 7-day expiry.
        $row = (function () use ($env) {
            $s = $env->pdo()->prepare("SELECT id, role, active, password_reset_token_hash, password_reset_expires_at FROM staff_users WHERE email = ?");
            $s->execute(['invited.person@example.test']);
            $r = $s->fetch();
            $s->closeCursor();
            return $r;
        })();
        assert_equal('pharmacy_staff', $row['role']);
        assert_equal(1, (int) $row['active']);
        assert_equal(hash('sha256', $token), $row['password_reset_token_hash'], 'only the hash is stored');
        $left = strtotime($row['password_reset_expires_at'] . ' UTC') - time();
        assert_true($left > 7 * 86400 - 600 && $left <= 7 * 86400 + 60, "the link should last about 7 days, not an hour (it has {$left}s)");

        // Audited: who created it and how, then that the invitation went out.
        $actions = (function () use ($env, $row) {
            $s = $env->pdo()->prepare("SELECT action, metadata_json FROM audit_log WHERE entity_type = 'staff_user' AND entity_id = ? ORDER BY id ASC");
            $s->execute([$row['id']]);
            $a = $s->fetchAll();
            $s->closeCursor();
            return $a;
        })();
        assert_equal(['staff_account_created', 'staff_invite_sent'], array_column($actions, 'action'));
        assert_contains('"via":"invite"', $actions[0]['metadata_json']);
    });

    run_test('a password posted to the form anyway is ignored: nobody can sign in until the link is used', function () use ($env, $invite, $signIn, $emailsTo) {
        $r = $invite('Sneaky Choice', 'sneaky.choice@example.test', 'pharmacy_staff', ['password' => 'admin-chose-this-1', 'password_confirm' => 'admin-chose-this-1']);
        assert_equal(302, $r['status']);
        $attempt = $signIn('sneaky.choice@example.test', 'admin-chose-this-1', 'cookies-invite-sneaky');
        assert_equal(200, $attempt['status'], 'sign-in must fail');
        assert_contains('Invalid email or password', $attempt['body']);
        assert_equal(1, count($emailsTo('sneaky.choice@example.test')));
        assert_true(strpos(json_encode($emailsTo('sneaky.choice@example.test')), 'admin-chose-this-1') === false, 'the posted password is not in the email');
    });

    run_test('the new person sets their own password with the link, can sign in, and the link works once', function () use ($env, $emailsTo, $linkIn, $setPassword, $signIn) {
        $token = $linkIn($emailsTo('invited.person@example.test')[0]);
        // Beyond the 60 minutes a normal reset link lives, since a new hire may not open the email at once.
        $page = http_request('GET', $env->baseUrl . '/admin/reset-password.php?token=' . $token);
        assert_contains('Choose a new password for invited.person@example.test', $page['body']);

        $ok = $setPassword($token, 'my-own-chosen-password-1', 'cookies-invite-set');
        assert_contains('Your password has been changed', $ok['body']);

        $in = $signIn('invited.person@example.test', 'my-own-chosen-password-1', 'cookies-invite-login');
        assert_equal(302, $in['status'], 'they can sign in with the password they chose');
        assert_contains('/admin/', $in['location'] ?? '');

        $reuse = http_request('GET', $env->baseUrl . '/admin/reset-password.php?token=' . $token);
        assert_contains('invalid or has expired', $reuse['body']);
    });

    run_test('an expired invitation is refused, and "Forgot your password?" issues a fresh one', function () use ($env, $invite, $emailsTo, $linkIn, $setPassword, $signIn) {
        $invite('Slow Reader', 'slow.reader@example.test');
        $old = $linkIn($emailsTo('slow.reader@example.test')[0]);
        (function () use ($env) {
            $env->pdo()->prepare("UPDATE staff_users SET password_reset_expires_at = ? WHERE email = ?")
                ->execute([gmdate('Y-m-d H:i:s', time() - 60), 'slow.reader@example.test']);
        })();
        $expired = http_request('GET', $env->baseUrl . '/admin/reset-password.php?token=' . $old);
        assert_contains('invalid or has expired', $expired['body']);

        // What the invitation email tells them to do.
        $jar = $env->tmpDir . '/cookies-invite-forgot.txt';
        $get = http_request('GET', $env->baseUrl . '/admin/forgot-password.php', ['cookie_jar' => $jar]);
        http_request('POST', $env->baseUrl . '/admin/forgot-password.php', ['cookie_jar' => $jar, 'body' => ['csrf_token' => extract_csrf($get['body']), 'email' => 'slow.reader@example.test']]);
        $emails = $emailsTo('slow.reader@example.test');
        assert_equal(2, count($emails), 'the invitation plus the fresh reset link');
        $fresh = $linkIn($emails[1]);
        assert_true($fresh !== $old);
        assert_contains('Your password has been changed', $setPassword($fresh, 'slow-readers-new-password-1', 'cookies-invite-slow-set')['body']);
        assert_equal(302, $signIn('slow.reader@example.test', 'slow-readers-new-password-1', 'cookies-invite-slow-login')['status']);
    });

    run_test('when the invitation email cannot be sent the account still exists, the admin is told what to do, and no link is shown', function () use ($env, $jar, $invite, $emailsTo) {
        $r = $invite('Mail Trouble', 'send-fails@example.test');
        assert_equal(302, $r['status']);
        $list = http_request('GET', $env->baseUrl . '/admin/staff/', ['cookie_jar' => $jar]);
        assert_contains('could not be sent', $list['body']);
        assert_contains('Forgot your password?', $list['body']);
        assert_true(strpos($list['body'], 'We emailed an invitation') === false, 'must not claim it was sent');
        assert_true(strpos($list['body'], 'reset-password.php?token=') === false, 'a failed send must not hand the admin the link');
        assert_equal(1, (int) $env->scalar("SELECT COUNT(*) FROM staff_users WHERE email = 'send-fails@example.test'"), 'the account is created');
        assert_equal([], $emailsTo('send-fails@example.test'), 'nothing was delivered');
        $actions = (function () use ($env) {
            $s = $env->pdo()->prepare("SELECT action FROM audit_log WHERE entity_type = 'staff_user' AND entity_id = (SELECT id FROM staff_users WHERE email = ?) ORDER BY id ASC");
            $s->execute(['send-fails@example.test']);
            $a = $s->fetchAll(PDO::FETCH_COLUMN);
            $s->closeCursor();
            return $a;
        })();
        assert_equal(['staff_account_created', 'staff_invite_failed'], $actions);
        // The flash is a status line, not a form: the failure is shown with the error styling.
        assert_true((bool) preg_match('#<div class="alert ">[^<]*could not be sent#', $list['body']), 'shown as an error');
    });

    run_test('submitting the same form twice (a double-click) creates one account and sends one invitation', function () use ($env, $jar, $emailsTo) {
        $get = http_request('GET', $env->baseUrl . '/admin/staff/new.php', ['cookie_jar' => $jar]);
        $body = ['csrf_token' => extract_csrf($get['body']), 'ott' => extract_ott($get['body']), 'name' => 'Double Click', 'email' => 'double.click@example.test', 'role' => 'pharmacy_staff'];
        $first  = http_request('POST', $env->baseUrl . '/admin/staff/new.php', ['cookie_jar' => $jar, 'body' => $body]);
        $second = http_request('POST', $env->baseUrl . '/admin/staff/new.php', ['cookie_jar' => $jar, 'body' => $body]);
        assert_equal(302, $first['status']);
        assert_equal(200, $second['status']);
        assert_contains('already submitted', $second['body']);
        assert_equal(1, (int) $env->scalar("SELECT COUNT(*) FROM staff_users WHERE email = 'double.click@example.test'"));
        assert_equal(1, count($emailsTo('double.click@example.test')), 'one invitation, not two');
    });

    run_test('an admin invitation creates an admin; the role comes from the dropdown, not from anything else', function () use ($env, $invite) {
        try {
            $invite('Second Admin', 'second.admin@example.test', 'admin');
            assert_equal('admin', $env->scalar("SELECT role FROM staff_users WHERE email = 'second.admin@example.test'"));
            $invite('Odd Role', 'odd.role@example.test', 'superuser');
            assert_equal('pharmacy_staff', $env->scalar("SELECT role FROM staff_users WHERE email = 'odd.role@example.test'"), 'an unknown role falls back to the least privileged one');
        } finally {
            // Later tests (staff management) count the active admins, so don't leave a second one behind.
            (function () use ($env) { $env->pdo()->exec("DELETE FROM staff_users WHERE email IN ('second.admin@example.test', 'odd.role@example.test')"); })();
        }
    });

    run_test('a pharmacy_staff account cannot open or post to the new-staff form', function () use ($env) {
        (function () use ($env) {
            $env->pdo()->prepare("INSERT INTO staff_users (name, email, password_hash, role) VALUES ('Plain Staff', 'plain.staff@example.test', ?, 'pharmacy_staff')")
                ->execute([password_hash('plain-staff-password-1', PASSWORD_DEFAULT)]);
        })();
        $j = $env->tmpDir . '/cookies-invite-plain.txt';
        $get = http_request('GET', $env->baseUrl . '/admin/login.php', ['cookie_jar' => $j]);
        $login = http_request('POST', $env->baseUrl . '/admin/login.php', ['cookie_jar' => $j, 'body' => ['csrf_token' => extract_csrf($get['body']), 'email' => 'plain.staff@example.test', 'password' => 'plain-staff-password-1']]);
        assert_equal(302, $login['status']);
        assert_equal(403, http_request('GET', $env->baseUrl . '/admin/staff/new.php', ['cookie_jar' => $j])['status']);
        $csrf = extract_csrf(http_request('GET', $env->baseUrl . '/admin/gs-requests/', ['cookie_jar' => $j])['body']);
        $post = http_request('POST', $env->baseUrl . '/admin/staff/new.php', ['cookie_jar' => $j, 'body' => ['csrf_token' => $csrf, 'ott' => 'x', 'name' => 'Nope', 'email' => 'nope@example.test', 'role' => 'admin']]);
        assert_equal(403, $post['status']);
        assert_equal(0, (int) $env->scalar("SELECT COUNT(*) FROM staff_users WHERE email = 'nope@example.test'"));
    });
};
