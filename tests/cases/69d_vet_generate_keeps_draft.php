<?php
// "Generate Activation Link" shares a form with the email composer, so the message being written is posted along
// with it. It used to be ignored: the page came back with the default template and a custom message was gone. Now
// the draft survives (through the redirect, like the new link itself), and the new link is put where the dead one,
// or the template's placeholder line, was. A message with no link gets the new link in a box to paste.
return function (TestEnv $env): void {
    $jar = $env->cookieJarStaff;
    $placeholder = '[click "Generate Activation Link" below, then re-open this template]';

    // An application that can have a link generated: approved, with a portal account waiting for activation.
    $makeApp = function (string $appStatus = 'approved', string $acctStatus = 'pending_activation', ?string $clinicEmail = null) use ($env): array {
        $uniq = bin2hex(random_bytes(3));
        $email = "gen.{$uniq}@example.test";
        $pdo = $env->pdo();
        $pdo->prepare(
            "INSERT INTO vet_applications (full_name, professional_email, mobile, registration_number, registration_state, qualification, year_qualified,
                 practice_type, clinic_name, clinic_address, clinic_city, clinic_state, clinic_pin, clinic_phone, clinic_email, status)
             VALUES ('Dr Draft Test', ?, '9000055555', ?, 'Maharashtra', 'BVSc', 2018, 'Small animal', 'Draft Clinic', '1 Rd', 'Pune', 'MH', '411001', '02011114444', ?, ?)"
        )->execute([$email, "MH-VET-{$uniq}", $clinicEmail, $appStatus]);
        $id = (int) $env->scalar('SELECT id FROM vet_applications WHERE professional_email = ?', [$email]);
        $pdo->prepare("INSERT INTO vet_accounts (vet_application_id, email, status, activation_token_hash, activation_expires_at) VALUES (?, ?, ?, ?, ?)")
            ->execute([$id, $email, $acctStatus, hash('sha256', "initial-{$uniq}"), gmdate('Y-m-d H:i:s', time() + 3600)]);
        return ['id' => $id, 'email' => $email];
    };
    $url  = fn (int $id): string => $env->baseUrl . "/admin/veterinary-applications/view.php?id={$id}";
    $page = fn (int $id): string => http_request('GET', $url($id), ['cookie_jar' => $jar])['body'];
    // Clicks "Generate Activation Link" with this in the composer (the whole composer form is posted with it).
    $generate = function (int $id, array $composer) use ($jar, $url, $page): array {
        $html = $page($id);
        return http_request('POST', $url($id), ['cookie_jar' => $jar, 'follow' => true, 'body' => array_merge(
            ['csrf_token' => extract_csrf($html), 'ott' => extract_ott($html), 'action' => 'generate_activation_link', 'sender' => 'ops@kuronyx.in', 'recipient' => '', 'subject' => '', 'body' => ''],
            $composer
        )]);
    };
    $bodyOf    = fn (string $html): ?string => preg_match('#<textarea id="email-body"[^>]*>(.*?)</textarea>#s', $html, $m) ? html_entity_decode($m[1], ENT_QUOTES) : null;
    $subjectOf = fn (string $html): ?string => preg_match('#id="email-subject" name="subject" value="([^"]*)"#', $html, $m) ? html_entity_decode($m[1], ENT_QUOTES) : null;
    $linkIn    = fn (?string $text): ?string => preg_match('#https://kuronyx\.in/for-veterinarians/activate\.php\?token=([a-f0-9]{64})#', (string) $text, $m) ? $m[1] : null;
    $tokenHash = fn (int $id) => $env->scalar('SELECT activation_token_hash FROM vet_accounts WHERE vet_application_id = ?', [$id]);
    $linkBox   = 'New activation link (shown once, valid 7 days';

    run_test('a custom message survives Generate: subject, body and sender come back, and the new link is shown to paste', function () use ($makeApp, $generate, $bodyOf, $subjectOf, $linkIn, $tokenHash, $linkBox) {
        $a = $makeApp();
        $custom = "Hello Dr Draft,\n\nBefore we activate your account we still need a copy of your clinic licence.\n\nThanks";
        $r = $generate($a['id'], ['sender' => 'hello@kuronyx.in', 'subject' => 'One more document', 'body' => $custom]);
        assert_equal(302, $r['post']['status'], 'Generate still redirects (the link survives a refresh)');
        assert_equal($custom, $bodyOf($r['body']), 'the message that was being written is still there');
        assert_equal('One more document', $subjectOf($r['body']));
        assert_true((bool) preg_match('#<option value="hello@kuronyx.in" selected>#', $r['body']), 'and the sender that was chosen');
        assert_true((bool) preg_match('#<option value="blank" selected>#', $r['body']), 'the template menu shows custom, not "Approved", since it is not that template');
        // The message has no link, so the new link is offered in a box.
        assert_contains($linkBox, $r['body']);
        assert_contains('It is not in your message. Paste it where you want it.', $r['body']);
        $token = null;
        assert_true((bool) preg_match('#<code>(https://kuronyx\.in/for-veterinarians/activate\.php\?token=([a-f0-9]{64}))</code>#', $r['body'], $m), 'the link box holds the link');
        assert_equal(hash('sha256', $m[2]), $tokenHash($a['id']), 'and it is the live token');
    });

    run_test('a dead link in the message is replaced by the new one, and the rest of the message is untouched', function () use ($makeApp, $generate, $bodyOf, $linkIn, $tokenHash, $linkBox) {
        $a = $makeApp();
        $first = $generate($a['id'], []);                        // an untouched composer: the Approved template with link A
        $linkA = $linkIn($bodyOf($first['body']));
        assert_true($linkA !== null, 'link A is in the default message');
        $edited = str_replace('Your veterinary account application has been approved.', 'Great news, Dr Draft, you are approved!', $bodyOf($first['body']));
        $second = $generate($a['id'], ['subject' => 'Your account', 'body' => $edited]);   // regenerate: link A dies
        $body = $bodyOf($second['body']);
        $linkB = $linkIn($body);
        assert_true($linkB !== null && $linkB !== $linkA, 'the message now holds the NEW link');
        assert_true(strpos($body, $linkA) === false, 'and not the dead one');
        assert_contains('Great news, Dr Draft, you are approved!', $body, 'the staff member\'s own wording is kept');
        assert_equal(hash('sha256', $linkB), $tokenHash($a['id']), 'the new link is the live token');
        assert_true(strpos($second['body'], $linkBox) === false, 'no extra link box when the message already holds the link');
    });

    run_test('the template\'s placeholder line is replaced by the new link', function () use ($makeApp, $generate, $bodyOf, $linkIn, $tokenHash, $placeholder) {
        $a = $makeApp();
        $draft = "Hi Dr Draft,\n\nSet your password here:\n{$placeholder}\n\nRegards";
        $r = $generate($a['id'], ['subject' => 'Activate', 'body' => $draft]);
        $body = $bodyOf($r['body']);
        $link = $linkIn($body);
        assert_true($link !== null, 'the placeholder became a link');
        assert_true(strpos($body, $placeholder) === false, 'the placeholder text is gone');
        assert_equal("Hi Dr Draft,\n\nSet your password here:\nhttps://kuronyx.in/for-veterinarians/activate.php?token={$link}\n\nRegards", $body, 'everything else is as typed');
        assert_equal(hash('sha256', $link), $tokenHash($a['id']));
    });

    run_test('an untouched composer behaves exactly as before: the Approved template with the new link already in it', function () use ($makeApp, $generate, $bodyOf, $subjectOf, $linkIn, $linkBox) {
        $a = $makeApp();
        $r = $generate($a['id'], []);
        assert_true((bool) preg_match('#<option value="approved" selected>#', $r['body']), 'the Approved template is selected');
        assert_equal('Your Kuronyx veterinary account is approved', $subjectOf($r['body']));
        assert_true($linkIn($bodyOf($r['body'])) !== null, 'the link is in the message');
        assert_true(strpos($r['body'], $linkBox) === false, 'no link box');
    });

    run_test('the recipient chosen (the clinic address) is kept too', function () use ($makeApp, $generate) {
        $a = $makeApp('approved', 'pending_activation', 'clinic.draft@example.test');
        $r = $generate($a['id'], ['recipient' => 'clinic.draft@example.test', 'subject' => 'Hello clinic', 'body' => 'A note for the clinic.']);
        assert_true((bool) preg_match('#<option value="clinic.draft@example.test" selected>#', $r['body']), 'the clinic recipient is still selected');
    });

    run_test('a REFUSED Generate keeps the message too (not approved / already activated)', function () use ($makeApp, $generate, $bodyOf, $subjectOf) {
        $rejected = $makeApp('rejected', 'suspended');
        $r = $generate($rejected['id'], ['subject' => 'Sorry', 'body' => 'A long message I do not want to lose.']);
        assert_equal(200, $r['status'], 'a refusal is not redirected');
        assert_contains('not currently approved', $r['body']);
        assert_equal('A long message I do not want to lose.', $bodyOf($r['body']));
        assert_equal('Sorry', $subjectOf($r['body']));

        $active = $makeApp('approved', 'active');
        $r2 = $generate($active['id'], ['subject' => 'Welcome', 'body' => 'Another message to keep.']);
        assert_contains('already activated', $r2['body']);
        assert_equal('Another message to keep.', $bodyOf($r2['body']));
    });

    run_test('the kept message can be SENT right away: its link is the live one, so the dead-link guard lets it through', function () use ($makeApp, $generate, $bodyOf, $linkIn, $page, $jar, $url, $env) {
        $a = $makeApp();
        $first = $generate($a['id'], []);
        $edited = "Dear Dr Draft,\n\n" . $bodyOf($first['body']);
        $r = $generate($a['id'], ['subject' => 'Your account', 'body' => $edited]);   // regenerate: the link in $edited is now dead
        $body = $bodyOf($r['body']);
        $html = $page($a['id']);
        // (the page above is a plain reload: the draft is gone, so send what the previous response showed)
        $send = http_request('POST', $url($a['id']), ['cookie_jar' => $jar, 'follow' => true, 'body' => [
            'csrf_token' => extract_csrf($html), 'ott' => extract_ott($html), 'action' => 'send_email',
            'sender' => 'ops@kuronyx.in', 'recipient' => $a['email'], 'subject' => 'Your account', 'body' => $body,
        ]]);
        assert_contains('Email sent.', $send['body']);
        $mail = $env->lastDryRunEmailTo($a['email']);
        assert_true($mail !== null && $linkIn($mail['body']) === $linkIn($body), 'the email that went out carries the live link');
    });
};
