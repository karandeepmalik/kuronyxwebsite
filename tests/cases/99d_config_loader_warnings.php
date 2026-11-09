<?php
// includes/config-loader.php finds db-config.php above public_html first and falls back to includes/ (local dev and the
// tests rely on the fallback, so it is NEVER refused). A Karandeep review comment: after moving the real file above
// public_html, a stale copy left inside includes/ would be ignored - and nobody would notice the exposed one. So the
// loader now writes to the error log: loudly when BOTH exist, and when only the in-folder copy is used on a request
// that is not local. This runs a COPY of the loader in a throwaway folder tree (the loader works out the folders from its
// own location) in a separate PHP process, with its error log pointed at a file, so nothing touches the real config.
return function (TestEnv $env): void {
    $loaderSource = $env->root . '/public_html/includes/config-loader.php';
    $secret = 'SUPER-SECRET-DB-PASSWORD-' . bin2hex(random_bytes(4));

    // Builds <tree>/public_html/includes/config-loader.php (+ optional db-config.php files) and runs the loader once
    // (or twice) as a request to $host. Returns what the loader returned and what it wrote to the error log.
    $run = function (string $name, bool $above, bool $inside, ?string $host, int $calls = 1) use ($env, $loaderSource, $secret): array {
        $tree = $env->tmpDir . '/cfgtree-' . $name;
        @mkdir($tree . '/public_html/includes', 0777, true);
        copy($loaderSource, $tree . '/public_html/includes/config-loader.php');
        $conf = "<?php\ndefine('DB_PASS', '{$secret}');\n";
        foreach ([[$above, $tree . '/db-config.php'], [$inside, $tree . '/public_html/includes/db-config.php']] as [$make, $path]) {
            if ($make) file_put_contents($path, $conf); else @unlink($path);
        }
        $log = $tree . '/error.log';
        @unlink($log);
        $runner = $tree . '/run.php';
        file_put_contents($runner, "<?php\n"
            . ($host !== null ? "\$_SERVER['SERVER_NAME'] = " . var_export($host, true) . ";\n" : '')
            . "require " . var_export($tree . '/public_html/includes/config-loader.php', true) . ";\n"
            . "\$p = null; for (\$i = 0; \$i < {$calls}; \$i++) { \$p = gs_config_path(); }\n"
            . "echo json_encode(\$p);\n");
        $cmd = escapeshellarg(PHP_BINARY) . ' -n -d log_errors=1 -d display_errors=0 -d error_log=' . escapeshellarg($log) . ' ' . escapeshellarg($runner) . ' 2>&1';
        exec($cmd, $out);
        $logText = is_file($log) ? (string) file_get_contents($log) : '';
        $returned = json_decode(implode('', $out), true);
        return ['returned' => is_string($returned) ? str_replace('\\', '/', $returned) : $returned, 'log' => $logText, 'tree' => str_replace('\\', '/', $tree)];
    };

    run_test('only the safe file above public_html: it is used and nothing is logged', function () use ($run) {
        $r = $run('above', true, false, 'kuronyx.in');
        assert_equal($r['tree'] . '/db-config.php', $r['returned']);
        assert_equal('', $r['log']);
    });

    run_test('both files exist: the safe one is still used, but a loud SECURITY line names the stale copy, on a live host and on localhost alike', function () use ($run) {
        foreach (['kuronyx.in', 'localhost', null] as $i => $host) {
            $r = $run('both' . $i, true, true, $host);
            assert_equal($r['tree'] . '/db-config.php', $r['returned'], 'the file above public_html is the one used (host: ' . var_export($host, true) . ')');
            assert_contains('[config] SECURITY', $r['log']);
            assert_contains('BOTH places', $r['log']);
            assert_contains($r['tree'] . '/public_html/includes/db-config.php', str_replace('\\', '/', $r['log']), 'it names the copy to delete');
            assert_contains('Delete that copy from the server', $r['log']);
        }
    });

    run_test('only the in-folder copy, on a live host: it still works (the fallback is never refused) and a warning says to move it', function () use ($run) {
        $r = $run('inside-live', false, true, 'kuronyx.in');
        assert_equal($r['tree'] . '/public_html/includes/db-config.php', $r['returned'], 'the fallback is still used');
        assert_contains('being read from inside the web folder', $r['log']);
        assert_contains('Move it one level above public_html', $r['log']);
        assert_true(strpos($r['log'], 'SECURITY') === false, 'this is the quieter warning, not the SECURITY one');
    });

    run_test('only the in-folder copy on a local request (localhost, 127.0.0.1, *.localhost, or a command-line run): still used and no noise, so local development and the tests stay quiet', function () use ($run) {
        foreach (['localhost', '127.0.0.1', 'kuronyx.localhost', null] as $i => $host) {
            $r = $run('inside-local' . $i, false, true, $host);
            assert_equal($r['tree'] . '/public_html/includes/db-config.php', $r['returned']);
            assert_equal('', $r['log'], 'no warning for ' . ($host ?? 'a command-line run (no server name)'));
        }
    });

    run_test('no config anywhere: null, and nothing to warn about', function () use ($run) {
        $r = $run('none', false, false, 'kuronyx.in');
        assert_equal(null, $r['returned']);
        assert_equal('', $r['log']);
    });

    run_test('the warning is written once per request even when the loader is called several times, and never contains what is in the file', function () use ($run, $secret) {
        $r = $run('once', true, true, 'kuronyx.in', 3);
        assert_equal(1, substr_count($r['log'], '[config] SECURITY'), 'one line for three calls');
        assert_true(strpos($r['log'], $secret) === false, 'only paths are logged, never contents');
        $r = $run('once-live', false, true, 'kuronyx.in', 3);
        assert_equal(1, substr_count($r['log'], 'being read from inside the web folder'));
        assert_true(strpos($r['log'], $secret) === false);
    });
};
