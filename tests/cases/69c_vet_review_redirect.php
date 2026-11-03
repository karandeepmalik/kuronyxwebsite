<?php
// Post/Redirect/Get on admin/veterinary-applications/view.php. A successful review action used to answer the POST
// with the result page itself, so a browser refresh RE-SENT it: Add Note appended the note again, Approve/Reject
// added another entry, and Generate Activation Link made a new token that killed the link just copied. Now a
// successful action answers with a redirect and the message is shown once from the session; the raw activation
// link (never stored) travels through the session once too. Errors/refusals stay on the page so nothing typed is lost.
return function (TestEnv $env): void {
    $jar = $env->cookieJarStaff;

    $makeApp = function (string $status = 'pending', ?string $email = null) use ($env): int {
        $uniq = bin2hex(random_bytes(3));
        $email ??= "prg.{$uniq}@example.test";
        $env->pdo()->prepare(
            "INSERT INTO vet_applications (full_name, professional_email, mobile, registration_number, registration_state, qualification, year_qualified,
                 practice_type, clinic_name, clinic_address, clinic_city, clinic_state, clinic_pin, clinic_phone, status)
             VALUES ('Dr Redirect Test', ?, '9000044444', ?, 'Maharashtra', 'BVSc', 2017, 'Small animal', 'Redirect Clinic', '1 Rd', 'Pune', 'MH', '411001', '02011113333', ?)"
        )->execute([$email, "MH-VET-{$uniq}", $status]);
        return (int) $env->scalar('SELECT id FROM vet_applications WHERE professional_email = ?', [$email]);
    };
    $url  = fn (int $id): string => $env->baseUrl . "/admin/veterinary-applications/view.php?id={$id}";
    $page = fn (int $id): string => http_request('GET', $url($id), ['cookie_jar' => $jar])['body'];
    // The raw POST (no following): exactly what the browser receives straight after clicking the button.
    $post = function (int $id, string $action, array $extra = [], string $note = 'Checked in test.') use ($jar, $url, $page): array {
        $html = $page($id);
        return http_request('POST', $url($id), ['cookie_jar' => $jar, 'body' => array_merge(
            ['csrf_token' => extract_csrf($html), 'expected_status' => extract_expected_status($html), 'action' => $action, 'note' => $note], $extra
        )]);
    };
    // The "Send an email" form of the page as it is right now.
    $send = function (int $id, string $recipient, string $body) use ($jar, $url, $page): array {
        $html = $page($id);
        return http_request('POST', $url($id), ['cookie_jar' => $jar, 'body' => [
            'csrf_token' => extract_csrf($html), 'ott' => extract_ott($html), 'action' => 'send_email',
            'sender' => 'ops@kuronyx.in', 'recipient' => $recipient, 'subject' => 'About your application', 'body' => $body,
        ]]);
    };
    $notes = fn (int $id): string => (string) $env->scalar('SELECT internal_notes FROM vet_applications WHERE id = ?', [$id]);
    $linkIn = fn (string $html): ?string => preg_match('#https://kuronyx\.in/for-veterinarians/activate\.php\?token=([a-f0-9]{64})#', $html, $m) ? $m[1] : null;
    $tokenHash = fn (int $id) => $env->scalar('SELECT activation_token_hash FROM vet_accounts WHERE vet_application_id = ?', [$id]);

    run_test('a successful action answers with a redirect to a plain GET of the page, not with the result page', function () use ($makeApp, $post) {
        $id = $makeApp('pending');
        $r = $post($id, 'request_info');
        assert_equal(302, $r['status'], 'a successful POST must redirect');
        assert_contains("/admin/veterinary-applications/view.php?id={$id}", (string) $r['location']);
        assert_true(strpos($r['body'], 'Marked as needing more information') === false, 'the message is not in the POST response itself');
    });

    run_test('Add Note: the message is shown once after the redirect, and reloading the page adds nothing', function () use ($makeApp, $post, $page, $notes) {
        $id = $makeApp('pending');
        $r = $post($id, 'add_note', [], 'Called the clinic, certificate looks genuine.');
        assert_equal(302, $r['status']);
        assert_contains('Note added.', $page($id), 'the outcome is shown after the redirect');
        assert_equal(1, substr_count($notes($id), 'Called the clinic, certificate looks genuine.'));
        // Refreshing is a GET now: the note is not appended again and the message is gone.
        $second = $page($id);
        $third  = $page($id);
        assert_true(strpos($second, 'Note added.') === false && strpos($third, 'Note added.') === false, 'the message is shown once');
        assert_equal(1, substr_count($notes($id), 'Called the clinic, certificate looks genuine.'), 'reloading did not append the note again');
    });

    run_test('Approve / Reject / Suspend / Request info all redirect and show their message once', function () use ($makeApp, $post, $page) {
        foreach ([
            ['approve', 'pending', 'Application approved'],
            ['reject', 'pending', 'Application rejected'],
            ['request_info', 'pending', 'Marked as needing more information'],
            ['suspend', 'approved', 'Account suspended'],
        ] as [$action, $from, $message]) {
            $id = $makeApp($from);
            $r = $post($id, $action);
            assert_equal(302, $r['status'], "{$action} must redirect");
            assert_contains($message, $page($id), "{$action}: the message after the redirect");
            assert_true(strpos($page($id), $message) === false, "{$action}: and only once");
        }
    });

    run_test('Approve: the activation link survives the redirect, is shown once, and is the live one', function () use ($makeApp, $post, $page, $linkIn, $tokenHash, $env) {
        $id = $makeApp('pending');
        assert_equal(302, $post($id, 'approve')['status']);
        $token = $linkIn($page($id));
        assert_true($token !== null, 'the activation link is in the page after the redirect (it exists nowhere else)');
        assert_equal(hash('sha256', $token), $tokenHash($id), 'and it is the real, current token');
        // Shown once: a reload does not show it again (it was removed from the session when read) ...
        assert_true($linkIn($page($id)) === null, 'a reload must not show the link again');
        // ... and reloading did not make a new one: the link that was copied still works.
        assert_equal(hash('sha256', $token), $tokenHash($id), 'the token is unchanged by reloading');
        $activate = http_request('GET', $env->baseUrl . '/for-veterinarians/activate.php?token=' . $token, ['cookie_jar' => $env->tmpDir . '/cookies-prg-activate.txt']);
        assert_contains('Set a password to activate', $activate['body'], 'the copied link still works');
    });

    run_test('Generate Activation Link: reloading does not mint a new token and kill the link that was just copied', function () use ($makeApp, $post, $page, $linkIn, $tokenHash, $env) {
        $id = $makeApp('pending');
        $post($id, 'approve');
        $page($id); // the approval's own link is shown (and used up) here
        $before = $tokenHash($id);

        assert_equal(302, $post($id, 'generate_activation_link')['status']);
        $token = $linkIn($page($id));
        assert_true($token !== null, 'the fresh link is shown after the redirect');
        assert_true(hash('sha256', $token) !== $before, 'generating replaced the previous token, as designed');
        $after = $tokenHash($id);
        assert_equal(hash('sha256', $token), $after);
        // The refresh the old code mishandled: reload the page a few times.
        $page($id); $page($id); $page($id);
        assert_equal($after, $tokenHash($id), 'reloading must not regenerate the token');
        assert_equal(1, (int) $env->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'activation_link_generated' AND entity_id = ?", [$id]), 'and only one generation was audited');
    });

    run_test('Send email: success redirects and shows "Email sent." once', function () use ($makeApp, $send, $page, $env) {
        $email = 'prg.sent.' . bin2hex(random_bytes(3)) . '@example.test';
        $id = $makeApp('pending', $email);
        $r = $send($id, $email, 'Please send the clinic address proof.');
        assert_equal(302, $r['status'], 'a sent email redirects');
        assert_contains('Email sent.', $page($id));
        assert_true(strpos($page($id), 'Email sent.') === false, 'shown once');
        $mail = $env->lastDryRunEmailTo($email);
        assert_true($mail !== null && strpos($mail['body'], 'clinic address proof') !== false, 'the email went out once, from the original POST');
    });

    // Errors and refusals are NOT redirected: the page keeps what was typed, and repeating one just refuses it again.
    run_test('a failed email send, a missing note and a stale page stay on the page (no redirect) and keep what was typed', function () use ($makeApp, $send, $post, $page, $jar, $url) {
        // A send that fails (the test harness fails this one address): 200, an error, and the draft is still in the box.
        $id = $makeApp('pending', 'send-fails@example.test');
        $r = $send($id, 'send-fails@example.test', 'A message that will not go out.');
        assert_equal(200, $r['status'], 'a failed send is not redirected');
        assert_contains('Email failed to send', $r['body']);
        assert_contains('A message that will not go out.', $r['body'], 'the draft is kept for a retry');

        // Approve with no note: refused on the page, note box marked.
        $other = $makeApp('pending');
        $r = $post($other, 'approve', [], '');
        assert_equal(200, $r['status']);
        assert_contains('Write what you checked before approving', $r['body']);

        // A stale page: refused on the page, and the note that was typed is kept in the box.
        $stale = $makeApp('pending');
        $html = $page($stale);
        $expected = extract_expected_status($html);
        $csrf = extract_csrf($html);
        $post($stale, 'reject'); // someone else decides first
        $r = http_request('POST', $url($stale), ['cookie_jar' => $jar, 'body' => ['csrf_token' => $csrf, 'expected_status' => $expected, 'action' => 'approve', 'note' => 'My careful note.']]);
        assert_equal(200, $r['status'], 'a refusal is not redirected');
        assert_contains('nothing was changed', $r['body']);
        assert_contains('My careful note.', $r['body'], 'the note is kept');
    });

    run_test('the message left by one action is shown only on that application\'s page', function () use ($makeApp, $post, $page) {
        $a = $makeApp('pending');
        $b = $makeApp('pending');
        $post($a, 'add_note', [], 'A note on A only.'); // redirected, but not yet followed
        assert_true(strpos($page($b), 'Note added.') === false, 'the other application does not show it');
        assert_contains('Note added.', $page($a), 'the application it belongs to does');
    });
};
