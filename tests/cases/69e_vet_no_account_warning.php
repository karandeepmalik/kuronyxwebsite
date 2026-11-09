<?php
// Approving an application whose email address already has a portal account (it belongs to ANOTHER application)
// creates no account for it: an account belongs to exactly one application and the address is unique. The vet
// cannot use the portal through the application that was just approved, so this is a warning staff must follow up,
// not the green success message it used to be. The follow-up stays visible (a banner on the page every time it is
// opened) and "Generate Activation Link" explains instead of saying "approve it first".
return function (TestEnv $env): void {
    $jar = $env->cookieJarStaff;

    $makeApp = function (string $status, string $email, bool $withAccount = false, string $acctStatus = 'suspended') use ($env): int {
        $uniq = bin2hex(random_bytes(3));
        $pdo = $env->pdo();
        $pdo->prepare(
            "INSERT INTO vet_applications (full_name, professional_email, mobile, registration_number, registration_state, qualification, year_qualified,
                 practice_type, clinic_name, clinic_address, clinic_city, clinic_state, clinic_pin, clinic_phone, status)
             VALUES ('Dr Account Test', ?, '9000066666', ?, 'Maharashtra', 'BVSc', 2019, 'Small animal', 'Account Clinic', '1 Rd', 'Pune', 'MH', '411001', '02011115555', ?)"
        )->execute([$email, "MH-VET-{$uniq}", $status]);
        $id = (int) $env->scalar('SELECT id FROM vet_applications WHERE registration_number = ?', ["MH-VET-{$uniq}"]);
        if ($withAccount) {
            $pdo->prepare('INSERT INTO vet_accounts (vet_application_id, email, status) VALUES (?, ?, ?)')->execute([$id, $email, $acctStatus]);
        }
        return $id;
    };
    $url  = fn (int $id): string => $env->baseUrl . "/admin/veterinary-applications/view.php?id={$id}";
    $page = fn (int $id): string => http_request('GET', $url($id), ['cookie_jar' => $jar])['body'];
    $act  = function (int $id, string $action) use ($jar, $url, $page): array {
        $html = $page($id);
        return http_request('POST', $url($id), ['cookie_jar' => $jar, 'follow' => true, 'body' => [
            'csrf_token' => extract_csrf($html), 'expected_status' => extract_expected_status($html), 'action' => $action, 'note' => 'Checked in test.',
        ]]);
    };
    $uniqEmail = fn (string $tag): string => "noacct.{$tag}." . bin2hex(random_bytes(3)) . '@example.test';
    $banner = 'No portal account for this application.';

    run_test('approving when the email already has an account is an amber WARNING that names the owner, not a green success', function () use ($env, $makeApp, $act, $page, $uniqEmail, $banner) {
        $email = $uniqEmail('owner');
        $owner = $makeApp('rejected', $email, true);     // VA-owner already holds the account for this address
        $dup   = $makeApp('pending', $email);            // a second application from the same address
        $r = $act($dup, 'approve');
        assert_equal(302, $r['post']['status'], 'it redirects like any successful action');
        $body = $r['body'];
        assert_contains("Application approved, but no portal account was created: an account for this email already belongs to VA-{$owner} (Rejected).", $body);
        assert_true(strpos($body, 'class="alert alert-warn"') !== false, 'amber warning');
        assert_true(strpos($body, 'class="alert alert-ok"') === false, 'and not the green success');

        // The facts: the application is approved, no account was created for it, and the conflict is audited.
        assert_equal('approved', $env->scalar('SELECT status FROM vet_applications WHERE id = ?', [$dup]));
        assert_equal(0, (int) $env->scalar('SELECT COUNT(*) FROM vet_accounts WHERE vet_application_id = ?', [$dup]), 'no account was made');
        assert_equal(1, (int) $env->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'vet_account_conflict' AND entity_id = ?", [$dup]));
        assert_contains('already exists for this email address', (string) $env->scalar('SELECT internal_notes FROM vet_applications WHERE id = ?', [$dup]));

        // The lasting banner: names the other application, links to it and says what to check.
        assert_contains($banner, $body);
        assert_contains("href=\"/admin/veterinary-applications/view.php?id={$owner}\">VA-{$owner}</a>", $body);
        assert_contains('The vet cannot use the portal', $body);
        assert_contains('it may be the one to re-approve', $body);
    });

    run_test('the follow-up does not disappear: the banner is there on every later visit, though the one-time message is gone', function () use ($makeApp, $act, $page, $uniqEmail, $banner) {
        $email = $uniqEmail('later');
        $owner = $makeApp('suspended', $email, true);
        $dup = $makeApp('pending', $email);
        $act($dup, 'approve');
        foreach ([1, 2] as $visit) {
            $html = $page($dup);
            assert_true(strpos($html, 'Application approved, but') === false, "visit {$visit}: the one-time message is not repeated");
            assert_contains($banner, $html, "visit {$visit}: the banner is still there");
            assert_contains("VA-{$owner}", $html);
        }
    });

    run_test('Generate Activation Link on such an application explains why there is no link, instead of "approve it first"', function () use ($makeApp, $act, $uniqEmail, $page, $jar, $url) {
        $email = $uniqEmail('gen');
        $owner = $makeApp('rejected', $email, true);
        $dup = $makeApp('pending', $email);
        $act($dup, 'approve');
        $html = $page($dup);
        $r = http_request('POST', $url($dup), ['cookie_jar' => $jar, 'follow' => true, 'body' => [
            'csrf_token' => extract_csrf($html), 'ott' => extract_ott($html), 'action' => 'generate_activation_link', 'sender' => 'ops@kuronyx.in', 'recipient' => '', 'subject' => '', 'body' => '',
        ]]);
        assert_equal(200, $r['status']);
        assert_contains("This application is approved but has no portal account, so there is no link to generate: an account for this email already belongs to VA-{$owner} (Rejected).", $r['body']);
        assert_true(strpos($r['body'], 'approve it first') === false, 'the misleading advice is gone');
    });

    run_test('an ordinary approval is still green and shows no banner; neither does a pending application or an approved one that has its account', function () use ($makeApp, $act, $page, $uniqEmail, $banner) {
        $fresh = $makeApp('pending', $uniqEmail('fresh'));
        $r = $act($fresh, 'approve');
        assert_contains('Application approved and a portal account has been created.', $r['body']);
        assert_true(strpos($r['body'], 'class="alert alert-ok"') !== false && strpos($r['body'], 'class="alert alert-warn"') === false, 'green, no warning (the element, not the stylesheet)');
        assert_true(strpos($page($fresh), $banner) === false, 'an approved application with its own account has no banner');

        assert_true(strpos($page($makeApp('pending', $uniqEmail('pend'))), $banner) === false, 'a pending application has no banner');
        assert_true(strpos($page($makeApp('approved', $uniqEmail('acct'), true, 'pending_activation')), $banner) === false, 'nor an approved one that has an account');
    });

    run_test('an approved application with no account and no other owner gets the plain version of the banner; an erased one gets none', function () use ($env, $makeApp, $page, $uniqEmail, $banner) {
        $lonely = $makeApp('approved', $uniqEmail('lonely'));      // approved but no account anywhere
        $html = $page($lonely);
        assert_contains($banner, $html);
        assert_contains('no account exists for it, so the vet cannot sign in through it. Reject it and approve it again', $html);
        assert_true(strpos($html, 'already belongs to') === false, 'no owner to name');

        $erased = $makeApp('approved', $uniqEmail('erased'));
        $env->pdo()->prepare('UPDATE vet_applications SET erased_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s'), $erased]);
        assert_true(strpos($page($erased), $banner) === false, 'an erased application is a record: no banner');
    });
};
