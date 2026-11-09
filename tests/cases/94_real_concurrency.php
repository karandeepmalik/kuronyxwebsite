<?php
// Real concurrency tests: many separate PHP processes, each with its own database connection,
// released at the same instant against reserve_login_attempt() / rate_limited() — the
// count-then-insert windows the app's SELECT ... FOR UPDATE (MySQL) / BEGIN IMMEDIATE (SQLite)
// wrapper exists to protect. The HTTP test server can't do this (php -S handles one request at a
// time), so these drive the functions directly.
//
// They run by default only against MySQL (KURONYX_TEST_MYSQL=...), because that is where gap-lock
// deadlocks can actually happen and where production runs. Set KURONYX_TEST_CONCURRENCY=1 to run
// them against SQLite too. KURONYX_TEST_WORKERS changes how many processes contend (default 24).
return function (TestEnv $env): void {
    $enabled = $env->isMysql || getenv('KURONYX_TEST_CONCURRENCY');
    $workers = max(4, (int) (getenv('KURONYX_TEST_WORKERS') ?: 24));
    $script  = $env->root . '/tests/lib/concurrency_worker.php';

    // Starts one process per job, all released together, and returns their result tokens.
    $race = function (array $jobs) use ($env, $script): array {
        $startAt = microtime(true) + 3.0; // headroom for slow process start-up
        $procs = [];
        foreach ($jobs as $i => [$task, $key]) {
            $pipes = [];
            $procs[$i] = [proc_open(
                ['php', '-c', $env->iniPath, $script, $task, $key, sprintf('%.4f', $startAt)],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            ), $pipes];
        }
        $out = [];
        foreach ($procs as $i => [$proc, $pipes]) {
            fclose($pipes[0]);
            $out[$i] = trim(stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]));
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
        }
        return $out;
    };
    $tally = function (array $results): array {
        $t = [];
        foreach ($results as $r) {
            $k = strncmp($r, 'ERR', 3) === 0 ? 'ERR' : $r;
            $t[$k] = ($t[$k] ?? 0) + 1;
        }
        return $t;
    };

    run_test("{$workers} processes failing the same login at once reserve exactly LOGIN_MAX_ATTEMPTS attempts, and none crash", function () use ($env, $enabled, $workers, $race, $tally) {
        if (!$enabled) skip_test('MySQL only — set KURONYX_TEST_MYSQL (or KURONYX_TEST_CONCURRENCY=1 for SQLite)');
        $key = 'race@example.test|10.0.0.1';
        $results = $race(array_fill(0, $workers, ['login', $key]));
        $t = $tally($results);
        $errors = array_values(array_filter($results, fn($r) => strncmp($r, 'ERR', 3) === 0));
        assert_equal(0, $t['ERR'] ?? 0, 'no contender may fail (a deadlock beyond the retry cap would surface as a 500 at login): ' . implode(' | ', array_slice($errors, 0, 3)));
        assert_equal(LOGIN_MAX_ATTEMPTS, $t['OK'] ?? 0, 'exactly the cap may be reserved, however the processes interleave — got ' . json_encode($t));
        assert_equal($workers - LOGIN_MAX_ATTEMPTS, $t['LOCKED'] ?? 0);
        assert_equal(LOGIN_MAX_ATTEMPTS, (int) $env->scalar('SELECT COUNT(*) FROM login_attempts WHERE identifier = ?', [$key]), 'the table must hold exactly the reserved attempts');
    });

    run_test("{$workers} processes hitting one rate-limit bucket at once let exactly maxHits through", function () use ($env, $enabled, $workers, $race, $tally) {
        if (!$enabled) skip_test('MySQL only — set KURONYX_TEST_MYSQL (or KURONYX_TEST_CONCURRENCY=1 for SQLite)');
        $bucket = 'race_bucket:10.0.0.2';
        $results = $race(array_fill(0, $workers, ['rate', $bucket]));
        $t = $tally($results);
        $errors = array_values(array_filter($results, fn($r) => strncmp($r, 'ERR', 3) === 0));
        assert_equal(0, $t['ERR'] ?? 0, 'no contender may fail: ' . implode(' | ', array_slice($errors, 0, 3)));
        assert_equal(5, $t['PASS'] ?? 0, 'exactly maxHits (5) may pass — got ' . json_encode($t));
        assert_equal($workers - 5, $t['BLOCKED'] ?? 0);
    });

    run_test('contention across several different logins and buckets at once does not deadlock', function () use ($env, $enabled, $workers, $race, $tally) {
        if (!$enabled) skip_test('MySQL only — set KURONYX_TEST_MYSQL (or KURONYX_TEST_CONCURRENCY=1 for SQLite)');
        // Different keys land in different index ranges of the same tables, which is exactly where
        // InnoDB gap locks from one transaction can collide with another's insert.
        $jobs = [];
        for ($i = 0; $i < $workers; $i++) {
            $jobs[] = $i % 2 === 0
                ? ['login', 'mixed' . ($i % 6) . '@example.test|10.0.0.3']
                : ['rate', 'mixed_bucket:' . ($i % 6)];
        }
        $results = $race($jobs);
        $errors = array_values(array_filter($results, fn($r) => strncmp($r, 'ERR', 3) === 0));
        assert_equal([], $errors, 'no contender may fail with a deadlock / lock-wait error: ' . implode(' | ', array_slice($errors, 0, 3)));
    });
};
