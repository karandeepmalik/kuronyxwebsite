<?php
// PHP refuses a file bigger than the host's upload_max_filesize BEFORE our code looks at it, and reports error code
// UPLOAD_ERR_INI_SIZE (or UPLOAD_ERR_FORM_SIZE). Those used to fall into one vague "Upload failed. Please try again." - and
// since PHP's default limit is only 2M, on a host that kept it a 5 MB prescription photo never reached our own size
// message (a Karandeep review comment). Now the size codes say the REAL limit, the other codes say what happened, and the
// forms print and enforce the limit that really applies (ours or the server's, whichever is smaller).
return function (TestEnv $env): void {
    run_test('server limits are read from PHP\'s shorthand, and the limit that applies is ours or the server\'s, whichever is smaller', function () use ($env) {
        require_once $env->root . '/public_html/includes/upload-limits.php';
        assert_equal(2097152, upload_ini_bytes('2M'));
        assert_equal(2097152, upload_ini_bytes('2m'));
        assert_equal(1073741824, upload_ini_bytes('1024M'));
        assert_equal(1073741824, upload_ini_bytes('1G'));
        assert_equal(524288, upload_ini_bytes('512K'));
        assert_equal(8388608, upload_ini_bytes('8388608'));
        foreach (['', '-1', 'abc', '2 apples'] as $odd) assert_equal(0, upload_ini_bytes($odd), "'{$odd}' cannot be read, so it is ignored");

        assert_equal(2097152, upload_limit_bytes('2M'), 'PHP\'s 2M default is below our 10 MB: that is the real limit');
        assert_equal(5242880, upload_limit_bytes('5M'));
        assert_equal(MAX_UPLOAD_BYTES, upload_limit_bytes('10M'));
        assert_equal(MAX_UPLOAD_BYTES, upload_limit_bytes('12M'));
        assert_equal(MAX_UPLOAD_BYTES, upload_limit_bytes('1024M'), 'Hostinger: far above ours, so ours applies');
        assert_equal(MAX_UPLOAD_BYTES, upload_limit_bytes(''), 'an unreadable server value does not lower the limit');
    });

    run_test('every PHP upload error code says something useful; the size codes name the real limit', function () use ($env) {
        require_once $env->root . '/public_html/includes/upload-limits.php';
        foreach ([UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE] as $code) {
            $msg = upload_error_message($code, '2M');
            assert_contains('larger than this server accepts (2 MB)', $msg, "code {$code}");
            assert_contains('smaller file, or a compressed copy', $msg);
            assert_true(strpos($msg, 'Upload failed') === false, 'not the old vague message');
        }
        assert_contains('(10 MB)', upload_error_message(UPLOAD_ERR_INI_SIZE, '1024M'), 'when the server allows more than we do, our limit is the one named');
        assert_contains('interrupted before it finished', upload_error_message(UPLOAD_ERR_PARTIAL));
        foreach ([UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION] as $code) {
            $msg = upload_error_message($code);
            assert_contains('problem on our side', $msg, "code {$code}");
            assert_contains('ops@kuronyx.in', $msg);
        }
        assert_equal('Upload failed. Please try again.', upload_error_message(99), 'an unknown code keeps the generic message');
    });

    // A real PHP server with a SMALL upload_max_filesize (1M), the same site and database as the main test server.
    run_test('on a server whose upload_max_filesize is 1 MB the cat owner form promises 1 MB, and a 2 MB file is refused with that limit named (not "Upload failed")', function () use ($env) {
        $port = 8976;
        $cmd = ['php', '-c', $env->iniPath, '-d', 'upload_max_filesize=1M', '-d', 'post_max_size=12M', '-S', "127.0.0.1:{$port}", '-t', $env->root . '/public_html'];
        $log = $env->tmpDir . '/server-small-upload.log';
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'w']], $pipes, $env->root);
        assert_true(is_resource($proc), 'the small-limit test server started');
        $base = "http://127.0.0.1:{$port}";
        try {
            $up = false;
            for ($i = 0; $i < 40 && !$up; $i++) {
                $c = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.3);
                if ($c) { fclose($c); $up = true; } else usleep(150000);
            }
            assert_true($up, 'the small-limit server is listening');

            $jar = $env->tmpDir . '/cookies-small-upload.txt';
            $get = http_request('GET', $base . '/for-cat-owners/index.php', ['cookie_jar' => $jar]);
            assert_contains('up to 1 MB', $get['body'], 'the form promises what the server will take');
            assert_true(strpos($get['body'], 'up to 10 MB') === false, 'not our own 10 MB');
            assert_equal(3, substr_count($get['body'], 'data-max-bytes="1048576"'), 'the browser check uses 1 MB on all three file inputs');

            $big = $env->tmpDir . '/two-megabytes.png';
            file_put_contents($big, str_repeat('A', 2 * 1048576));
            $post = http_request('POST', $base . '/for-cat-owners/index.php', ['cookie_jar' => $jar, 'body' => [
                'csrf_token' => extract_csrf($get['body']), 'ott' => extract_ott($get['body']),
                'owner_full_name' => 'Asha Owner', 'owner_email' => 'asha@example.test', 'owner_phone' => '9876543210',
                'owner_address' => '1 Road', 'owner_city' => 'Pune', 'owner_state' => 'Maharashtra', 'owner_pin' => '411001',
                'patient_name' => 'Tom', 'vet_name' => 'Dr V', 'vet_clinic' => 'Clinic', 'vet_email' => 'v@example.test', 'vet_phone' => '02012345678',
                'requested_formulation' => 'oral', 'consent' => '1',
                'prescription' => new CURLFile($big, 'image/png', 'two-megabytes.png'),
            ]]);
            assert_equal(200, $post['status'], 'answered on the page, not a crash');
            assert_contains('larger than this server accepts (1 MB)', $post['body'], 'the real limit is named');
            assert_true(strpos($post['body'], 'Upload failed. Please try again.') === false, 'not the old vague message');
            assert_equal(0, (int) $env->scalar("SELECT COUNT(*) FROM gs_requests WHERE owner_email = 'asha@example.test' AND patient_name = 'Tom' AND created_at >= ?", [gmdate('Y-m-d H:i:s', time() - 120)]), 'nothing was saved');
        } finally {
            if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); }
            $env->pdo()->exec("DELETE FROM rate_limit_hits WHERE bucket LIKE 'cat_owner_submit:%'");
        }
    });

    run_test('the three public forms print and enforce upload_limit_bytes(), never the bare constant (static check)', function () use ($env) {
        foreach (['for-cat-owners/index.php', 'for-veterinarians/apply/index.php', 'for-veterinarians/portal/new-request.php'] as $rel) {
            $src = file_get_contents($env->root . '/public_html/' . $rel);
            assert_true(strpos($src, 'upload_size_text(MAX_UPLOAD_BYTES)') === false, "{$rel}: prints the constant, not the real limit");
            assert_true(strpos($src, 'data-max-bytes="<?= MAX_UPLOAD_BYTES ?>"') === false, "{$rel}: the browser check uses the constant, not the real limit");
        }
    });
};
