<?php
// private_storage_path() (includes/storage-path.php) used to fall back SILENTLY to private_storage_fallback when
// PRIVATE_STORAGE_PATH was missing - on the live server too (a Karandeep review comment). After a config rewrite that
// would split prescriptions across two folders: downloads look in the configured one only, erasure deletes from it only,
// and the other may not be backed up. Now the fallback is for LOCAL requests only (localhost, 127.0.0.1, *.localhost, a
// command-line run); on any other host it is refused, loudly: one error-log line and a plain 503.
// This runs the real files in a throwaway folder tree, in a separate PHP process, with its error log pointed at a file.
return function (TestEnv $env): void {
    $src = $env->root . '/public_html/includes/';

    // Builds <tree>/public_html/includes/{config-loader,log-safe,db,storage-path}.php (copies of the real ones) and a
    // db-config.php above public_html that sets PRIVATE_STORAGE_PATH (or not), then runs $body as one request to $host.
    $run = function (string $name, ?string $host, ?string $configured, string $body) use ($env, $src): array {
        $tree = str_replace('\\', '/', $env->tmpDir) . '/storagetree-' . $name;
        @mkdir($tree . '/public_html/includes', 0777, true);
        foreach (['config-loader.php', 'log-safe.php', 'db.php', 'storage-path.php'] as $f) copy($src . $f, $tree . '/public_html/includes/' . $f);
        file_put_contents($tree . '/db-config.php', "<?php\ndefine('DB_DRIVER', 'sqlite');\ndefine('DB_NAME', " . var_export($tree . '/x.sqlite', true) . ");\n"
            . ($configured !== null ? "define('PRIVATE_STORAGE_PATH', " . var_export($configured, true) . ");\n" : ''));
        @rmdir($tree . '/private_storage_fallback');
        $log = $tree . '/error.log';
        @unlink($log);
        file_put_contents($tree . '/run.php', "<?php\n"
            . ($host !== null ? "\$_SERVER['SERVER_NAME'] = " . var_export($host, true) . ";\n" : '')
            . "require " . var_export($tree . '/public_html/includes/storage-path.php', true) . ";\n" . $body);
        $out = [];
        // The same PHP settings as this runner (the real site has mbstring; a bare -n would not).
        $ini = php_ini_loaded_file();
        exec(escapeshellarg(PHP_BINARY) . ($ini ? ' -c ' . escapeshellarg($ini) : ' -n') . ' -d log_errors=1 -d display_errors=0 -d error_log=' . escapeshellarg($log) . ' ' . escapeshellarg($tree . '/run.php') . ' 2>&1', $out);
        clearstatcache();
        return ['out' => implode("\n", $out), 'log' => is_file($log) ? (string) file_get_contents($log) : '', 'tree' => $tree, 'fallbackExists' => is_dir($tree . '/private_storage_fallback')];
    };
    $try = "try { echo json_encode(['path' => str_replace('\\\\', '/', private_storage_path())]); } catch (StorageNotConfiguredException \$e) { echo json_encode(['refused' => \$e->getMessage()]); }\n";

    run_test('a live host with PRIVATE_STORAGE_PATH set uses it, and says nothing', function () use ($run, $try) {
        $r = $run('set-live', 'kuronyx.in', '/home/u1/domains/kuronyx.in/private_storage/', $try);
        assert_equal(['path' => '/home/u1/domains/kuronyx.in/private_storage'], json_decode($r['out'], true));
        assert_equal('', $r['log']);
        assert_true(!$r['fallbackExists'], 'the fallback folder is not created');
    });

    run_test('a live host with PRIVATE_STORAGE_PATH missing or empty is REFUSED, with one loud log line, and no fallback folder is created', function () use ($run, $try) {
        foreach (['unset' => null, 'empty' => ''] as $name => $configured) {
            foreach (['kuronyx.in', 'www.kuronyx.in', 'staging.example.com'] as $i => $host) {
                $r = $run("refuse-{$name}-{$i}", $host, $configured, $try);
                $got = json_decode($r['out'], true);
                assert_true(isset($got['refused']), "{$host} ({$name}): the fallback must be refused, got: " . $r['out']);
                assert_contains('[storage] PRIVATE_STORAGE_PATH is not set on a live host', $r['log']);
                assert_contains('REFUSED', $r['log']);
                assert_true(!$r['fallbackExists'], "{$host} ({$name}): nothing may be created in the fallback folder");
            }
        }
    });

    run_test('the refusal is logged once per request even though the function is called several times, and the log names no path or person', function () use ($run) {
        $r = $run('once', 'kuronyx.in', null, "for (\$i = 0; \$i < 3; \$i++) { try { private_storage_path(); } catch (StorageNotConfiguredException \$e) {} }\necho 'done';\n");
        assert_equal(1, substr_count($r['log'], '[storage]'));
        assert_true(strpos($r['log'], $r['tree']) === false, 'no folder path in the line');
    });

    run_test('local requests (localhost, 127.0.0.1, ::1, *.localhost, a command-line run) still use the fallback, quietly - local development and the tests are unaffected', function () use ($run, $try) {
        foreach (['localhost', '127.0.0.1', '::1', 'kuronyx.localhost', null] as $i => $host) {
            $r = $run("local-{$i}", $host, null, $try);
            $got = json_decode($r['out'], true);
            assert_true(isset($got['path']) && substr($got['path'], -strlen('/private_storage_fallback')) === '/private_storage_fallback', 'fallback for ' . var_export($host, true) . ': ' . $r['out']);
            assert_equal('', $r['log'], 'no log line for ' . var_export($host, true));
            assert_true($r['fallbackExists'], 'the local fallback folder is created, as before');
            assert_true(strpos($got['path'], str_replace('\\', '/', $r['tree']) . '/public_html') === false, 'and it is outside public_html');
        }
    });

    run_test('uncaught, the refusal is a plain 503-style page that says uploads and documents are temporarily unavailable, not "something went wrong"', function () use ($run, $env) {
        $r = $run('page', 'kuronyx.in', null, "private_storage_path();\n");
        assert_contains('Temporarily unavailable', $r['out']);
        assert_contains('Uploads and documents cannot be used right now', $r['out']);
        assert_true(strpos($r['out'], 'Something went wrong') === false);
        assert_true(strpos($r['out'], 'StorageNotConfiguredException') === false && strpos($r['out'], $r['tree']) === false, 'nothing technical reaches the visitor');
        assert_contains('Uncaught StorageNotConfiguredException', $r['log'], 'the handler still logs it');
        // And the handler really answers 503 (the page above cannot show a status code in a command-line run).
        $db = file_get_contents($env->root . '/public_html/includes/db.php');
        assert_true((bool) preg_match('/http_response_code\(\$unavailable \? 503 : 500\)/', $db), 'a 503, not a 500');
    });

    run_test('every document and upload path goes through private_storage_path(), so the refusal covers all of them (static check)', function () use ($env) {
        $root = $env->root . '/public_html';
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->getExtension() !== 'php') continue;
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root)));
            $text = (string) file_get_contents($f->getPathname());
            assert_true(strpos($text, 'private_storage_fallback') === false || in_array($rel, ['/includes/storage-path.php', '/includes/db-config.sample.php'], true), "{$rel}: only storage-path.php (and a comment in the sample config) may name the fallback folder");
        }
    });
};
