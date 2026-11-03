<?php
// The host allows requests of up to 1 GB, so the 10 MB-per-file limit is only enforced by our own code AFTER the whole
// file has arrived: a 300 MB scan on a phone connection was uploaded in full, then refused, and nothing on the cat-owner or
// vet-portal form even said there was a limit. The forms now print the limit next to the upload fields, js/upload-limit.js
// refuses a too-big file the moment it is chosen, and the server's own refusal says how big the file was and what the limit is.
return function (TestEnv $env): void {
    $pages = ['for-cat-owners/index.php', 'for-veterinarians/apply/index.php', 'for-veterinarians/portal/new-request.php'];

    run_test('every file input on the three forms carries the limit, the limit is printed, and the script is loaded', function () use ($env, $pages) {
        foreach ($pages as $rel) {
            $src = file_get_contents($env->root . '/public_html/' . $rel);
            preg_match_all('~<input type="file"(?:<\?=.*?\?>|[^>])*>~', $src, $inputs); // a PHP echo tag ends in ">" too
            assert_true(count($inputs[0]) >= 3, "{$rel}: expected the upload inputs");
            foreach ($inputs[0] as $tag) {
                assert_contains('data-max-bytes="<?= upload_limit_bytes() ?>"', $tag, "{$rel}: a file input has no limit for the browser check: {$tag}");
            }
            assert_contains('<script src="/js/upload-limit.js"></script>', $src, "{$rel} must load the upload-limit script");
            assert_contains('upload_size_text(upload_limit_bytes())', $src, "{$rel} must print the limit the server really applies (ours or PHP's, whichever is smaller), not a typed number");
            assert_true(strpos($src, 'up to 10MB') === false, "{$rel}: the limit must come from MAX_UPLOAD_BYTES");
        }
    });

    run_test('the pages show the real limit next to the uploads', function () use ($env) {
        $cat = http_request('GET', $env->baseUrl . '/for-cat-owners/index.php', ['cookie_jar' => $env->tmpDir . '/cookies-ul-cat.txt'])['body'];
        assert_contains('Prescription upload (PDF, JPG or PNG, up to 10 MB) - required', $cat);
        assert_contains('Each file can be up to 10 MB.', $cat);
        assert_contains('data-max-bytes="10485760"', $cat);
        $apply = http_request('GET', $env->baseUrl . '/for-veterinarians/apply/index.php', ['cookie_jar' => $env->tmpDir . '/cookies-ul-apply.txt'])['body'];
        assert_contains('PDF, JPG or PNG, up to 10 MB each.', $apply);
        assert_contains('data-max-bytes="10485760"', $apply);
        $js = http_request('GET', $env->baseUrl . '/js/upload-limit.js');
        assert_equal(200, $js['status']);
        assert_contains('KuronyxUploadLimit', $js['body']);
    });

    run_test('the browser check: exactly 10 MB passes, one byte over is refused with the size and the limit (run under Node)', function () use ($env) {
        $node = trim((string) @shell_exec('node --version 2>&1'));
        if (!preg_match('/^v\d+/', $node)) skip_test('Node.js is not installed here');
        $js = 'const vm=require("vm"),fs=require("fs");const sb={window:{}};vm.createContext(sb);'
            . 'vm.runInContext(fs.readFileSync(' . json_encode($env->root . '/public_html/js/upload-limit.js') . ',"utf8"),sb);'
            . 'const U=sb.window.KuronyxUploadLimit, M=10485760;'
            . 'process.stdout.write(JSON.stringify({exact:U.tooBigMessage({size:M},M), over:U.tooBigMessage({size:M+1},M), big:U.tooBigMessage({size:24536064},M),'
            . 'huge:U.tooBigMessage({size:314572800},M), none:U.tooBigMessage(null,M), nolimit:U.tooBigMessage({size:M*5},0), t:U.sizeText(M)}));';
        $f = tempnam(sys_get_temp_dir(), 'ul') . '.js';
        file_put_contents($f, $js);
        $r = json_decode((string) shell_exec('node ' . escapeshellarg($f) . ' 2>&1'), true);
        @unlink($f);
        assert_true(is_array($r), 'the Node check did not return JSON');
        assert_equal('', $r['exact'], 'a file of exactly the limit is allowed');
        assert_contains('the limit is 10 MB per file', $r['over']);
        assert_equal('That file is 23.4 MB - the limit is 10 MB per file. Please choose a smaller file, or a compressed copy.', $r['big']);
        assert_contains('That file is 300 MB', $r['huge']);
        assert_equal('', $r['none'], 'no file chosen is not an error');
        assert_equal('', $r['nolimit'], 'a field without a limit is never refused');
        assert_equal('10 MB', $r['t']);
    });

    run_test('the server refuses a too-big file and an empty one in plain words, under the file, with the typed fields kept', function () use ($env) {
        $env->pdo()->exec("DELETE FROM rate_limit_hits WHERE bucket LIKE 'cat_owner_submit:%'");
        $big = $env->tmpDir . '/eleven-mb.pdf';
        $fh = fopen($big, 'wb'); fwrite($fh, '%PDF-1.4' . str_repeat('0', 11 * 1048576 - 8)); fclose($fh);
        $empty = $env->tmpDir . '/empty.pdf';
        file_put_contents($empty, '');
        $base = ['owner_full_name' => 'Asha Owner', 'owner_email' => 'asha@example.test', 'owner_phone' => '9876543210', 'owner_address' => '1 Road',
            'owner_city' => 'Pune', 'owner_state' => 'Maharashtra', 'owner_pin' => '411001', 'patient_name' => 'Tom', 'vet_name' => 'Dr V',
            'vet_clinic' => 'Clinic', 'vet_email' => 'v@example.test', 'vet_phone' => '02012345678', 'requested_formulation' => 'oral', 'consent' => '1'];
        $post = function (string $path, string $jarName) use ($env, $base) {
            $jar = $env->tmpDir . '/' . $jarName;
            $get = http_request('GET', $env->baseUrl . '/for-cat-owners/index.php', ['cookie_jar' => $jar]);
            return http_request('POST', $env->baseUrl . '/for-cat-owners/index.php', ['cookie_jar' => $jar,
                'body' => array_merge(['csrf_token' => extract_csrf($get['body']), 'ott' => extract_ott($get['body'])], $base, ['prescription' => new CURLFile($path, 'application/pdf', basename($path))])]);
        };
        $r = $post($big, 'cookies-ul-big.txt');
        if ($r['status'] === 413) skip_test('the PHP running the tests has a post_max_size below 11 MB');
        assert_field_error($r['body'], 'prescription', 'That file is 11 MB - the limit is 10 MB per file. Please choose a smaller file, or a compressed copy.', 'cat');
        assert_true((bool) preg_match('~name="owner_full_name" value="Asha Owner"~', $r['body']), 'what they typed is kept');
        $r = $post($empty, 'cookies-ul-empty.txt');
        assert_field_error($r['body'], 'prescription', 'That file is empty. Please choose a different file.', 'cat');
        @unlink($big);
        $env->pdo()->exec("DELETE FROM rate_limit_hits WHERE bucket LIKE 'cat_owner_submit:%'");
    });
};
