<?php
return function (TestEnv $env): void {
    $submitCatOwner = function (string $label, string $filePath, string $fileName, string $mime) use ($env): array {
        $env->pdo()->exec("DELETE FROM rate_limit_hits WHERE bucket = 'cat_owner_submit:127.0.0.1'");
        $jar = $env->tmpDir . "/cookies-followup-{$label}.txt";
        $get = http_request('GET', $env->baseUrl . '/for-cat-owners/index.php', ['cookie_jar' => $jar]);
        $post = http_request('POST', $env->baseUrl . '/for-cat-owners/index.php', [
            'cookie_jar' => $jar,
            'body' => [
                'csrf_token' => extract_csrf($get['body']), 'ott' => extract_ott($get['body']),
                'owner_full_name' => "Followup {$label}", 'owner_email' => "followup-{$label}@example.test", 'owner_phone' => '9998887771',
                'owner_address' => '1 Park Street', 'owner_city' => 'Mumbai', 'owner_state' => 'Maharashtra', 'owner_pin' => '400001',
                'patient_name' => 'Momo', 'vet_name' => 'Dr. Rao', 'vet_clinic' => 'Rao Clinic',
                'vet_email' => 'rao@example.test', 'vet_phone' => '9123456780',
                'requested_formulation' => 'oral', 'consent' => '1',
                'prescription' => new CURLFile($filePath, $mime, $fileName),
            ],
        ]);
        $env->pdo()->exec("DELETE FROM rate_limit_hits WHERE bucket = 'cat_owner_submit:127.0.0.1'");
        return $post;
    };

    run_test('a PDF carrying JavaScript or embedded files is refused; a plain PDF is accepted', function () use ($env, $submitCatOwner) {
        $evil = $env->tmpDir . '/evil.pdf';
        file_put_contents($evil, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /OpenAction << /S /JavaScript /JS (app.alert(1)) >> >>\nendobj\n%%EOF\n");
        $r = $submitCatOwner('evilpdf', $evil, 'rx.pdf', 'application/pdf');
        assert_equal(200, $r['status']);
        preg_match('#class="alert">([^<]*)#', $r['body'], $mm);
        assert_contains('scripts or embedded files', $r['body'], 'got: ' . ($mm[1] ?? 'no alert'));
        assert_equal(0, (int) $env->scalar("SELECT COUNT(*) FROM gs_requests WHERE owner_email = 'followup-evilpdf@example.test'"));

        $plain = $env->tmpDir . '/plain.pdf';
        file_put_contents($plain, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");
        $ok = $submitCatOwner('plainpdf', $plain, 'rx.pdf', 'application/pdf');
        assert_equal(302, $ok['status'], 'an ordinary PDF must still be accepted');
        assert_equal(1, (int) $env->scalar("SELECT COUNT(*) FROM gs_requests WHERE owner_email = 'followup-plainpdf@example.test'"));
    });

    run_test('a PDF hiding JavaScript in a compressed object stream or a #-escaped name is refused; encrypted PDFs too', function () use ($env, $submitCatOwner) {
        $z = gzcompress('5 0 << /S /JavaScript /JS (app.alert(1)) >>');
        $variants = [
            'objstm'  => "%PDF-1.5\n7 0 obj\n<< /Type /ObjStm /N 1 /First 4 /Filter /FlateDecode /Length " . strlen($z) . " >>\nstream\n{$z}\nendstream\nendobj\n%%EOF\n",
            'escaped' => "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /AA << /O << /S /J#61vaScript /J#53 (x) >> >> >>\nendobj\n%%EOF\n",
            'encrypt' => "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R /Encrypt 9 0 R >>\n%%EOF\n",
        ];
        foreach ($variants as $label => $bytes) {
            $f = $env->tmpDir . "/{$label}.pdf";
            file_put_contents($f, $bytes);
            $r = $submitCatOwner("pdf{$label}", $f, 'rx.pdf', 'application/pdf');
            assert_equal(200, $r['status'], "{$label} must be refused");
            assert_equal(0, (int) $env->scalar("SELECT COUNT(*) FROM gs_requests WHERE owner_email = 'followup-pdf{$label}@example.test'"), "{$label} created a case");
        }
        // A compressed object stream with nothing active in it is fine.
        $clean = gzcompress('5 0 << /Type /Page >>');
        $f = $env->tmpDir . '/cleanobjstm.pdf';
        file_put_contents($f, "%PDF-1.5\n7 0 obj\n<< /Type /ObjStm /N 1 /First 4 /Filter /FlateDecode >>\nstream\n{$clean}\nendstream\nendobj\n%%EOF\n");
        $ok = $submitCatOwner('pdfcleanobjstm', $f, 'rx.pdf', 'application/pdf');
        assert_equal(302, $ok['status'], 'a clean object stream must be accepted');
    });

    run_test('login treats accent/malformed email variants as one lockout bucket', function () use ($env) {
        $env->pdo()->exec("DELETE FROM login_attempts");
        $env->pdo()->exec("DELETE FROM rate_limit_hits WHERE bucket LIKE 'login_ip:%'");
        $jar = $env->tmpDir . '/cookies-accent-login.txt';
        for ($i = 0; $i < 9; $i++) {
            $get = http_request('GET', $env->baseUrl . '/admin/login.php', ['cookie_jar' => $jar]);
            $r = http_request('POST', $env->baseUrl . '/admin/login.php', [
                'cookie_jar' => $jar,
                'body' => ['csrf_token' => extract_csrf($get['body']), 'email' => "adm\xC3\xADn{$i}@example.test", 'password' => 'wrong-password-x'],
            ]);
        }
        assert_contains('Too many failed attempts', $r['body'], 'varying the spelling must not reset the per-account counter');
        $env->pdo()->exec("DELETE FROM login_attempts");
        $env->pdo()->exec("DELETE FROM rate_limit_hits WHERE bucket LIKE 'login_ip:%'");
    });

    run_test('downloads are attachments with a sandboxing CSP and a UTF-8 filename*', function () use ($env) {
        $docId = (int) $env->scalar('SELECT id FROM gs_request_documents ORDER BY id LIMIT 1');
        $env->pdo()->prepare('UPDATE gs_request_documents SET original_filename = ? WHERE id = ?')->execute(["r\xC3\xA9sum\xC3\xA9;x.png", $docId]);
        $r = http_request('GET', $env->baseUrl . "/download.php?kind=gs_request&doc_id={$docId}", ['cookie_jar' => $env->cookieJarStaff]);
        assert_equal(200, $r['status']);
        assert_contains('attachment', $r['headers']);
        assert_contains("filename*=UTF-8''r%C3%A9sum%C3%A9x.png", $r['headers']);
        assert_contains('sandbox', $r['headers']);
    });

    run_test('an activation link can only be emailed to the vet\'s own professional address, never the clinic address', function () use ($env) {
        $pdo = $env->pdo();
        $pdo->exec("INSERT INTO vet_applications (full_name, professional_email, mobile, registration_number, registration_state, qualification, year_qualified, practice_type, clinic_name, clinic_address, clinic_city, clinic_state, clinic_pin, clinic_phone, clinic_email, status)
                    VALUES ('Dr Link', 'dr.link@example.test', '9000000000', 'REG1', 'MH', 'BVSc', 2015, 'Clinic', 'Link Clinic', '1 Rd', 'Pune', 'MH', '411001', '0201234567', 'frontdesk@example.test', 'approved')");
        $id = (int) $pdo->lastInsertId();
        $jar = $env->cookieJarStaff;
        $view = http_request('GET', $env->baseUrl . "/admin/veterinary-applications/view.php?id={$id}", ['cookie_jar' => $jar]);
        $r = http_request('POST', $env->baseUrl . "/admin/veterinary-applications/view.php?id={$id}", [
            'cookie_jar' => $jar,
            'body' => ['csrf_token' => extract_csrf($view['body']), 'ott' => extract_ott($view['body']), 'action' => 'send_email',
                'sender' => 'ops@kuronyx.in', 'recipient' => 'frontdesk@example.test', 'subject' => 'Activate',
                'body' => 'Link: https://kuronyx.in/for-veterinarians/activate.php?token=' . str_repeat('a', 64)],
        ]);
        assert_contains('own professional email address', $r['body']);
        assert_equal(null, $env->lastDryRunEmailTo('frontdesk@example.test'), 'nothing may have been sent to the clinic address');
    });

    run_test('a vet signing in is audited as a vet, with their account id as the actor', function () use ($env) {
        $accountId = (int) $env->scalar("SELECT id FROM vet_accounts WHERE email = 'portal.vet@example.test'");
        $env->pdo()->prepare("UPDATE vet_accounts SET status = 'active', password_hash = ? WHERE id = ?")->execute([password_hash('audit-vet-password-1', PASSWORD_DEFAULT), $accountId]);
        $appId = (int) $env->scalar('SELECT vet_application_id FROM vet_accounts WHERE id = ?', [$accountId]);
        $env->pdo()->prepare("UPDATE vet_applications SET status = 'approved' WHERE id = ?")->execute([$appId]);
        $env->pdo()->exec("DELETE FROM rate_limit_hits WHERE bucket LIKE 'login_ip:%'");

        $jar = $env->tmpDir . '/cookies-vet-audit.txt';
        $get = http_request('GET', $env->baseUrl . '/for-veterinarians/login.php', ['cookie_jar' => $jar]);
        $login = http_request('POST', $env->baseUrl . '/for-veterinarians/login.php', [
            'cookie_jar' => $jar,
            'body' => ['csrf_token' => extract_csrf($get['body']), 'email' => 'portal.vet@example.test', 'password' => 'audit-vet-password-1'],
        ]);
        assert_equal(302, $login['status']);
        $row = $env->pdo()->query("SELECT actor_type, actor_id FROM audit_log WHERE action = 'vet_login' ORDER BY id DESC LIMIT 1")->fetch();
        assert_equal('vet', $row['actor_type']);
        assert_equal($accountId, (int) $row['actor_id']);
    });

    run_test('forgot-password does the same kind of work for an unknown address as for a real one (no bcrypt only on one side)', function () use ($env) {
        $src = file_get_contents($env->root . '/public_html/admin/forgot-password.php')
             . file_get_contents($env->root . '/public_html/for-veterinarians/forgot-password.php');
        assert_true(strpos($src, 'password_hash(') === false, 'the not-found branch must not run password_hash(), which the real branch never does');
    });

    run_test('setup.php does not blow up on a hostile token cookie, and token-bearing pages are not cacheable', function () use ($env) {
        $r = http_request('GET', $env->baseUrl . '/admin/setup.php?token=wrong', ['headers' => ['Cookie: token[]=x']]);
        assert_equal(403, $r['status']);
        foreach (['/admin/reset-password.php?token=nope', '/for-veterinarians/reset-password.php?token=nope', '/for-veterinarians/activate.php?token=nope'] as $path) {
            $r = http_request('GET', $env->baseUrl . $path);
            assert_contains('no-store', $r['headers'], "{$path} must send Cache-Control: no-store");
            assert_contains('no-referrer', strtolower($r['headers']), "{$path} must not leak a Referer");
        }
    });
};
