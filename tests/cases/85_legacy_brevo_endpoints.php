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

    // Every request in this suite comes from the same test-runner IP, and other test
    // files already submit to (and, after this one, still need to submit to) these same
    // public forms — so rather than assuming how many hits are "left" in the 8/hour
    // budget, read the current count and submit exactly enough more to cross it, then
    // clear this bucket's rows afterward so later tests in other files aren't left
    // artificially rate-limited by what this test did.
    $exhaustRateLimit = function (TestEnv $env, string $bucket, string $url, string $csrf, string $jar) {
        $remaining = 8 - (int) $env->scalar('SELECT COUNT(*) FROM rate_limit_hits WHERE bucket = ?', [$bucket]);
        assert_true($remaining > 0, 'expected some rate-limit budget left to exhaust for this bucket');
        for ($i = 0; $i < $remaining; $i++) {
            $r = http_request('POST', $env->baseUrl . $url, ['cookie_jar' => $jar, 'body' => ['csrf_token' => $csrf]]);
            assert_true(strpos($r['body'], 'Too many submissions') === false, "attempt {$i} should not be rate-limited yet");
        }
        $blocked = http_request('POST', $env->baseUrl . $url, ['cookie_jar' => $jar, 'body' => ['csrf_token' => $csrf]]);
        (function () use ($env, $bucket) {
            $env->pdo()->prepare('DELETE FROM rate_limit_hits WHERE bucket = ?')->execute([$bucket]);
        })();
        return $blocked;
    };

    run_test('the cat-owner public form rate-limits repeated submissions from the same IP', function () use ($env, $exhaustRateLimit) {
        $jar = $env->tmpDir . '/cookies-ratelimit-catowner.txt';
        $get = http_request('GET', $env->baseUrl . '/for-cat-owners/index.php', ['cookie_jar' => $jar]);
        $csrf = extract_csrf($get['body']);
        // Deliberately blank required fields so each attempt fails fast on validation
        // rather than actually creating gs_requests rows.
        $last = $exhaustRateLimit($env, 'cat_owner_submit:127.0.0.1', '/for-cat-owners/index.php', $csrf, $jar);
        assert_contains('Too many submissions', $last['body']);
    });

    run_test('the vet-apply public form rate-limits repeated submissions from the same IP', function () use ($env, $exhaustRateLimit) {
        $jar = $env->tmpDir . '/cookies-ratelimit-vetapply.txt';
        $get = http_request('GET', $env->baseUrl . '/for-veterinarians/apply/index.php', ['cookie_jar' => $jar]);
        $csrf = extract_csrf($get['body']);
        $last = $exhaustRateLimit($env, 'vet_apply_submit:127.0.0.1', '/for-veterinarians/apply/index.php', $csrf, $jar);
        assert_contains('Too many submissions', $last['body']);
    });

    run_test('a blocked (rate-limited) request still records its own hit', function () use ($env) {
        // rate_limited() used to be a check-then-act split: the caller only called
        // record_rate_limit_hit() in the success branch, so a request that got blocked
        // never added a row — meaning a burst of concurrent requests could all read the
        // count before any of them recorded a hit, and all slip through. The fix records
        // every attempt unconditionally, including ones that end up rejected, so a bucket
        // that has just rejected a request should show maxHits + 1 rows, not maxHits.
        $bucket = 'cat_owner_submit:127.0.0.1';
        $env->pdo()->prepare('DELETE FROM rate_limit_hits WHERE bucket = ?')->execute([$bucket]);

        $jar = $env->tmpDir . '/cookies-ratelimit-hitcount.txt';
        $get = http_request('GET', $env->baseUrl . '/for-cat-owners/index.php', ['cookie_jar' => $jar]);
        $csrf = extract_csrf($get['body']);
        for ($i = 0; $i < 8; $i++) {
            http_request('POST', $env->baseUrl . '/for-cat-owners/index.php', ['cookie_jar' => $jar, 'body' => ['csrf_token' => $csrf]]);
        }
        $blocked = http_request('POST', $env->baseUrl . '/for-cat-owners/index.php', ['cookie_jar' => $jar, 'body' => ['csrf_token' => $csrf]]);
        assert_contains('Too many submissions', $blocked['body']);

        $hits = (int) $env->scalar('SELECT COUNT(*) FROM rate_limit_hits WHERE bucket = ?', [$bucket]);
        assert_equal(9, $hits, 'the blocked 9th attempt should itself have been recorded, not skipped');

        $env->pdo()->prepare('DELETE FROM rate_limit_hits WHERE bucket = ?')->execute([$bucket]);
    });
};
