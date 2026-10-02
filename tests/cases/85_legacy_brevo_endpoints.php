<?php
// These cover the security fixes to public_html/php/* (the legacy pre-GS-441524
// endpoints): webhook auth, the Origin check actually blocking processing (not just
// hiding the response), and per-IP rate limiting. None of these tests let a request
// reach the real Brevo API — each is crafted to fail validation (bad email/fields)
// *before* the curl call, since the test harness has no real Brevo key and no
// EMAIL_DRY_RUN escape hatch for this legacy code.
return function (TestEnv $env): void {
    run_test('webhook.php rejects a request without the correct secret', function () use ($env) {
        $r = http_request('POST', $env->baseUrl . '/php/webhook.php', [
            'headers' => ['Content-Type: application/json'],
            'body' => json_encode(['event' => 'delivered', 'email' => 'nobody@example.test']),
        ]);
        assert_equal(403, $r['status']);
    });

    run_test('webhook.php rejects a wrong secret even with a well-formed payload', function () use ($env) {
        $r = http_request('POST', $env->baseUrl . '/php/webhook.php?secret=wrong-secret', [
            'headers' => ['Content-Type: application/json'],
            'body' => json_encode(['event' => 'delivered', 'email' => 'nobody@example.test']),
        ]);
        assert_equal(403, $r['status']);
    });

    run_test('webhook.php accepts the correct secret and ignores a non-delivered event without calling Brevo', function () use ($env) {
        $r = http_request('POST', $env->baseUrl . '/php/webhook.php?secret=test-webhook-secret-not-real', [
            'headers' => ['Content-Type: application/json'],
            'body' => json_encode(['event' => 'opened', 'email' => 'nobody@example.test']),
        ]);
        assert_equal(200, $r['status']);
        assert_contains('Ignored non-delivered event', $r['body']);
    });

    run_test('webhook.php does not subscribe recipients of non-welcome (transactional) emails', function () use ($env) {
        foreach ([['template_id' => 99], []] as $extra) { // another template, and a non-template send (no id at all)
            $r = http_request('POST', $env->baseUrl . '/php/webhook.php?secret=test-webhook-secret-not-real', [
                'headers' => ['Content-Type: application/json'],
                'body' => json_encode(['event' => 'delivered', 'email' => 'owner@example.test'] + $extra),
            ]);
            assert_equal(200, $r['status']);
            assert_contains('not a newsletter welcome email', $r['body']);
        }
    });

    run_test('webhook.log is no longer written under the web-accessible php/ folder', function () use ($env) {
        // A 'delivered' + valid-secret request would reach the real Brevo API (skipped
        // here — see file header), but the two calls above already appended log lines;
        // confirm they landed off-webroot instead of public_html/php/webhook.log.
        assert_true(is_file($env->storageDir . '/webhook.log'), 'expected the log under the private storage dir');
        assert_true(!is_file($env->root . '/public_html/php/webhook.log'), 'the log must not be written back under public_html/php/');
    });

    run_test('send-welcome.php rejects a disallowed Origin even though it used to only hide the response', function () use ($env) {
        $r = http_request('POST', $env->baseUrl . '/php/send-welcome.php', [
            'headers' => ['Origin: https://evil.test', 'Content-Type: application/json'],
            'body' => json_encode(['email' => 'someone@example.test']),
        ]);
        assert_equal(403, $r['status']);
        assert_contains('Origin not allowed', $r['body']);
    });

    run_test('send-welcome.php rate-limits repeated requests from the same IP', function () use ($env) {
        // Origin is allowed and the request is well-formed enough to pass the Origin/
        // rate-limit gates, but the email is invalid so it 400s before ever reaching
        // the Brevo curl call — lets this be hammered safely in a test.
        $headers = ['Origin: https://kuronyx.in', 'Content-Type: application/json'];
        $body = json_encode(['email' => 'not-an-email']);
        for ($i = 0; $i < 5; $i++) {
            $r = http_request('POST', $env->baseUrl . '/php/send-welcome.php', ['headers' => $headers, 'body' => $body]);
            assert_equal(400, $r['status'], "attempt {$i} should fail validation, not be rate-limited yet");
        }
        $sixth = http_request('POST', $env->baseUrl . '/php/send-welcome.php', ['headers' => $headers, 'body' => $body]);
        assert_equal(429, $sixth['status'], 'the 6th request within the window should be rate-limited');
        assert_contains('Too many requests', $sixth['body']);
    });

    run_test('send-enquiry.php also rejects a disallowed Origin', function () use ($env) {
        $r = http_request('POST', $env->baseUrl . '/php/send-enquiry.php', [
            'headers' => ['Origin: https://evil.test', 'Content-Type: application/json'],
            'body' => json_encode(['name' => 'X', 'clinic' => 'Y', 'city' => 'Z', 'email' => 'x@example.test', 'phone' => '1', 'message' => 'hi']),
        ]);
        assert_equal(403, $r['status']);
    });

    run_test('send-enquiry.php rate-limits repeated requests from the same IP', function () use ($env) {
        // Missing fields fail validation before the curl call, same reasoning as above.
        $headers = ['Origin: https://kuronyx.in', 'Content-Type: application/json'];
        $body = json_encode(['name' => '', 'clinic' => '', 'city' => '', 'email' => '', 'phone' => '', 'message' => '']);
        for ($i = 0; $i < 5; $i++) {
            $r = http_request('POST', $env->baseUrl . '/php/send-enquiry.php', ['headers' => $headers, 'body' => $body]);
            assert_equal(400, $r['status'], "attempt {$i} should fail validation, not be rate-limited yet");
        }
        $sixth = http_request('POST', $env->baseUrl . '/php/send-enquiry.php', ['headers' => $headers, 'body' => $body]);
        assert_equal(429, $sixth['status'], 'the 6th request within the window should be rate-limited');
    });

    // The intake forms reserve a rate-limit hit up front and hand it back when a submission is
    // bounced for ordinary field errors (rate_limit_reserve()/rate_limit_release()), so blank-field
    // POSTs no longer fill the bucket. Fill it directly instead, then check the next POST is refused,
    // and clear this bucket's rows afterward so later tests aren't left rate-limited.
    $fillBucket = function (TestEnv $env, string $bucket, int $rows) {
        $env->pdo()->prepare('DELETE FROM rate_limit_hits WHERE bucket = ?')->execute([$bucket]);
        $ins = $env->pdo()->prepare('INSERT INTO rate_limit_hits (bucket) VALUES (?)');
        for ($i = 0; $i < $rows; $i++) $ins->execute([$bucket]);
    };

    run_test('the cat-owner public form rate-limits repeated submissions from the same IP', function () use ($env, $fillBucket) {
        $jar = $env->tmpDir . '/cookies-ratelimit-catowner.txt';
        $get = http_request('GET', $env->baseUrl . '/for-cat-owners/index.php', ['cookie_jar' => $jar]);
        $csrf = extract_csrf($get['body']);
        $fillBucket($env, 'cat_owner_submit:127.0.0.1', 8);
        $blocked = http_request('POST', $env->baseUrl . '/for-cat-owners/index.php', ['cookie_jar' => $jar, 'body' => ['csrf_token' => $csrf]]);
        $count = (int) $env->scalar('SELECT COUNT(*) FROM rate_limit_hits WHERE bucket = ?', ['cat_owner_submit:127.0.0.1']);
        $env->pdo()->prepare('DELETE FROM rate_limit_hits WHERE bucket = ?')->execute(['cat_owner_submit:127.0.0.1']);
        assert_contains('Too many submissions', $blocked['body']);
        assert_equal(8, $count, 'a blocked attempt is not itself recorded');
    });

    run_test('the vet-apply public form rate-limits repeated submissions from the same IP', function () use ($env, $fillBucket) {
        $jar = $env->tmpDir . '/cookies-ratelimit-vetapply.txt';
        $get = http_request('GET', $env->baseUrl . '/for-veterinarians/apply/index.php', ['cookie_jar' => $jar]);
        $csrf = extract_csrf($get['body']);
        $fillBucket($env, 'vet_apply_submit:127.0.0.1', 8);
        $blocked = http_request('POST', $env->baseUrl . '/for-veterinarians/apply/index.php', ['cookie_jar' => $jar, 'body' => ['csrf_token' => $csrf]]);
        $env->pdo()->prepare('DELETE FROM rate_limit_hits WHERE bucket = ?')->execute(['vet_apply_submit:127.0.0.1']);
        assert_contains('Too many submissions', $blocked['body']);
    });

    run_test('field-validation failures on the intake forms do not use up the hourly submission allowance', function () use ($env) {
        foreach ([['/for-cat-owners/index.php', 'cat_owner_submit:127.0.0.1'], ['/for-veterinarians/apply/index.php', 'vet_apply_submit:127.0.0.1']] as [$url, $bucket]) {
            $env->pdo()->prepare('DELETE FROM rate_limit_hits WHERE bucket = ?')->execute([$bucket]);
            $jar = $env->tmpDir . '/cookies-ratelimit-release.txt';
            @unlink($jar);
            $get = http_request('GET', $env->baseUrl . $url, ['cookie_jar' => $jar]);
            $csrf = extract_csrf($get['body']);
            // 12 blank-field submissions: well past the 8/hour budget if each counted.
            for ($i = 0; $i < 12; $i++) {
                $r = http_request('POST', $env->baseUrl . $url, ['cookie_jar' => $jar, 'body' => ['csrf_token' => $csrf, 'ott' => extract_ott($get['body'])]]);
                assert_true(strpos($r['body'], 'Too many submissions') === false, "{$url}: blank submission {$i} must not be rate-limited");
                assert_contains('Please check the highlighted fields', $r['body']);
                $get = $r; // the re-render carries the fresh one-time token
            }
            assert_equal(0, (int) $env->scalar('SELECT COUNT(*) FROM rate_limit_hits WHERE bucket = ?', [$bucket]), "{$url}: field errors hand their reserved hit back");
        }
    });
    run_test('the legacy endpoints no longer accept localhost origins, and enforce honeypot + length caps', function () use ($env) {
        $clear = function () use ($env) {
            $env->pdo()->exec("DELETE FROM rate_limit_hits WHERE bucket IN ('send_enquiry:127.0.0.1','send_welcome:127.0.0.1')");
        };
        $clear();
        $good = ['name' => 'X', 'clinic' => 'Y', 'city' => 'Z', 'email' => 'x@example.test', 'phone' => '12345', 'message' => 'hi'];

        $r = http_request('POST', $env->baseUrl . '/php/send-enquiry.php', [
            'headers' => ['Origin: http://localhost:8000', 'Content-Type: application/json'], 'body' => json_encode($good),
        ]);
        assert_equal(403, $r['status'], 'localhost is not a production origin');

        $h = ['Origin: https://kuronyx.in', 'Content-Type: application/json'];
        $r = http_request('POST', $env->baseUrl . '/php/send-enquiry.php', ['headers' => $h, 'body' => json_encode($good + ['bot-field' => 'spam'])]);
        assert_equal(200, $r['status']);
        assert_contains('"success":true', $r['body']);

        $r = http_request('POST', $env->baseUrl . '/php/send-enquiry.php', ['headers' => $h, 'body' => json_encode(['message' => str_repeat('a', 5001)] + $good)]);
        assert_equal(400, $r['status'], 'an over-long message must be rejected before reaching Brevo');
        assert_contains('too long', $r['body']);

        $r = http_request('POST', $env->baseUrl . '/php/send-enquiry.php', ['headers' => $h, 'body' => json_encode(['name' => ['array']] + $good)]);
        assert_equal(400, $r['status'], 'a non-string field is a validation error, not a 500');

        $r = http_request('POST', $env->baseUrl . '/php/send-welcome.php', ['headers' => $h, 'body' => json_encode(['email' => 'a@example.test', 'bot-field' => 'spam'])]);
        assert_equal(200, $r['status']);
        $clear();
    });

    run_test('captcha-config.php reports no site key when Turnstile is not configured', function () use ($env) {
        $r = http_request('GET', $env->baseUrl . '/php/captcha-config.php');
        assert_equal(200, $r['status']);
        assert_equal('{}', trim($r['body']));
    });
};
