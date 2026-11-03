<?php
// A Cloudflare Turnstile token works once. send-enquiry.php / send-welcome.php check it BEFORE the fields and the mailer,
// so when the server then refuses the submission (400 field error, mailer 500) the form still held the used token and the
// retry was refused with the verification error until the page was reloaded. The three forms now reset the widget after
// every answer. The enquiry forms also check the phone number in the browser with the server's own rule, so a number the
// server would refuse ("+91 98xxx ext 5") is caught before the token is spent. No browser here: the pages are checked
// statically, and the rule is compared with the server's text.
return function (TestEnv $env): void {
    $pages = ['index.html' => 'enqForm', 'js/subscribe.js' => 'form'];

    run_test('every form that sends a Turnstile token resets the widget right after the answer, before it can retry', function () use ($env, $pages) {
        foreach ($pages as $file => $formVar) {
            $src = file_get_contents($env->root . '/public_html/' . $file);
            $send = strpos($src, 'KuronyxPost.send(');
            $reset = strpos($src, "KuronyxCaptcha.reset({$formVar})");
            assert_true($send !== false, "{$file} no longer posts through KuronyxPost.send");
            assert_true($reset !== false, "{$file} must call KuronyxCaptcha.reset({$formVar}) (a used token is refused on the retry)");
            assert_true($reset > $send, "{$file}: the reset must come after the send");
            // Between the send and the reset there must be no early exit (throw/return) that would skip it.
            $between = substr($src, $send, $reset - $send);
            assert_true(!preg_match('/\b(throw|return)\b/', $between), "{$file}: nothing may throw or return between the send and the reset");
        }
        // The function they call exists in the widget script.
        assert_contains('reset(form)', file_get_contents($env->root . '/public_html/js/captcha.js'), 'captcha.js must define reset(form)');
    });

    run_test('the browser checks the phone number with exactly the rule the server uses', function () use ($env) {
        $server = file_get_contents($env->root . '/public_html/php/send-enquiry.php');
        assert_true((bool) preg_match('~preg_match\(\'(/\^\[0-9\+\(\)\\\\-\\\\s\.\]\{5,30\}\$/)\', \$phone\)~', $server, $m), 'could not find the phone rule in send-enquiry.php');
        $rule = $m[1];
        foreach (['index.html'] as $file) {
            $src = file_get_contents($env->root . '/public_html/' . $file);
            assert_contains("!{$rule}.test(v)", $src, "{$file} must check the phone with the server's rule {$rule}");
            assert_contains("n === 'phone'", $src);
        }
    });

    run_test('the phone rule refuses what the server refuses and accepts ordinary numbers (run under Node)', function () use ($env) {
        $node = trim((string) @shell_exec('node --version 2>&1'));
        if (!preg_match('/^v\d+/', $node)) skip_test('Node.js is not installed here');
        $src = file_get_contents($env->root . '/public_html/index.html');
        assert_true((bool) preg_match('~n === \'phone\'\) bad = !(/[^\n]*?/)\.test\(v\);~', $src, $m), 'phone rule not found in the contact page');
        $js = 'const re = ' . $m[1] . ';'
            . 'const cases = {"+91 98765 43210": true, "(022) 2345-6789": true, "98765.43210": true, "12345": true,'
            . '"+91 98xxx ext 5": false, "9876 ext 5": false, "1234": false, "call me": false, "98765-43210 x": false, "": false,'
            . '"+91 98765 43210 98765 43210 98765 43210": false};'
            . 'const out = {}; for (const k in cases) out[k] = re.test(k.trim()) === cases[k];'
            . 'process.stdout.write(JSON.stringify(out));';
        $file = tempnam(sys_get_temp_dir(), 'phone') . '.js'; // a file, not `node -e`: Windows mangles the quotes
        file_put_contents($file, $js);
        $res = json_decode((string) shell_exec('node ' . escapeshellarg($file) . ' 2>&1'), true);
        @unlink($file);
        assert_true(is_array($res), 'the Node check did not return JSON');
        $wrong = array_keys(array_filter($res, fn ($ok) => !$ok));
        assert_equal([], $wrong, 'these numbers are judged differently from what is intended');
    });

    run_test('whatever the browser lets through after its cleanup, the server accepts (non-breaking spaces, dashes, symbols; run under Node)', function () use ($env) {
        $node = trim((string) @shell_exec('node --version 2>&1'));
        if (!preg_match('/^v\d+/', $node)) skip_test('Node.js is not installed here');
        $server = file_get_contents($env->root . '/public_html/php/send-enquiry.php');
        assert_true((bool) preg_match('~preg_match\(\'(/\^\[0-9\+\(\)\\\\-\\\\s\.\]\{5,30\}\$/)\', \$phone\)~', $server, $m), 'server phone rule not found');
        $serverRule = $m[1];

        foreach (['index.html'] as $file) {
            $src = file_get_contents($env->root . '/public_html/' . $file);
            // The page's own cleanup expression and rule, lifted from the source so a change there is what gets tested.
            assert_true((bool) preg_match('~phoneField\.value = (phoneField\.value[^;]+);~', $src, $c), "{$file}: the phone cleanup line is missing");
            assert_true((bool) preg_match('~n === \'phone\'\) bad = !(/[^\n]*?/)\.test\(v\);~', $src, $r), "{$file}: the phone rule is missing");
            $probes = ["+91 98765 43210", "+91\u{00a0}98765\u{00a0}43210", "98765 43210\u{00a0}", "\u{00a0}98765 43210", "+91\u{202f}98765\u{202f}43210",
                "98765\u{2003}43210", "98765\u{3000}43210", "98765\t43210", "98765\u{2013}43210", "98765\u{2011}43210", "９８７６５４３２１０",
                "(022) 2345-6789", "+91 98xxx ext 5", ".....", "123", str_repeat('9', 31), "98765\u{200b}43210"];
            $js = 'const probes = ' . json_encode($probes) . ';'
                . 'const re = ' . $r[1] . ';'
                . 'const out = probes.map(p => { const phoneField = { value: p }; const v = (' . $c[1] . '); return { v, ok: re.test(v) }; });'
                . 'process.stdout.write(JSON.stringify(out));';
            $f = tempnam(sys_get_temp_dir(), 'phone') . '.js';
            file_put_contents($f, $js);
            $res = json_decode((string) shell_exec('node ' . escapeshellarg($f) . ' 2>&1'), true);
            @unlink($f);
            assert_true(is_array($res), "{$file}: the Node check did not return JSON");
            foreach ($res as $i => $row) {
                if (!$row['ok']) continue;
                assert_true((bool) preg_match($serverRule, trim($row['v'])), "{$file}: the browser lets " . json_encode($probes[$i]) . ' through but the server refuses ' . json_encode($row['v']));
            }
            // The cases people really paste are accepted, not just tolerated: nbsp-separated numbers pass in the browser.
            assert_true($res[1]['ok'] && $res[2]['ok'] && $res[3]['ok'] && $res[4]['ok'], "{$file}: numbers pasted with non-breaking spaces must be accepted after cleanup");
            assert_true(!$res[12]['ok'] && !$res[14]['ok'] && !$res[15]['ok'], "{$file}: 'ext' numbers, three digits and 31 characters must still be refused");
        }
    });

    run_test('a refused phone number says WHY (characters, too short, too long) in both enquiry forms', function () use ($env) {
        foreach (['index.html'] as $file) {
            $src = file_get_contents($env->root . '/public_html/' . $file);
            assert_contains('Phone number: use digits only', $src);
            assert_contains('Phone number looks too short', $src, "{$file}: a short number must not be told 'use digits only'");
            assert_contains('Phone number is too long (30 characters at most)', $src);
        }
    });

    run_test('the server really does refuse the number the browser now catches, with the field message and without sending', function () use ($env) {
        // Turnstile is off in the test environment, so this is the 400 a visitor used to get AFTER the token was spent.
        // Earlier tests fill this IP's enquiry allowance on purpose: clear it before and after.
        $clear = fn () => $env->pdo()->exec("DELETE FROM rate_limit_hits WHERE bucket LIKE 'send_enquiry:%'");
        $clear();
        $r = http_request('POST', $env->baseUrl . '/php/send-enquiry.php', [
            'headers' => ['Content-Type: application/json', 'Origin: https://kuronyx.in'],
            'body' => json_encode(['name' => 'A', 'clinic' => 'B', 'city' => 'C', 'email' => 'a@b.in', 'phone' => '+91 98xxx ext 5', 'message' => 'hello']),
        ]);
        $clear();
        assert_equal(400, $r['status']);
        assert_contains('Please fill in all fields with valid information.', $r['body']);
    });
};
