<?php
// One contender in a real concurrency test (see tests/cases/94_real_concurrency.php): a separate
// PHP process, with its own database connection, that calls one of the app's serialized-window
// helpers at a pre-agreed instant so many of them hit the database at the same moment, then
// prints exactly one result token and exits.
//
// Usage: php concurrency_worker.php <login|rate> <key> <start-at unix time with fractions>
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

[, $task, $key, $startAt] = $argv + [null, '', '', '0'];
$_SERVER['SCRIPT_NAME'] = '/concurrency_worker.php';
require __DIR__ . '/../../public_html/includes/auth.php';

try {
    db(); // connect before the barrier, so connection setup time isn't part of the race
    while (microtime(true) < (float) $startAt) {
        usleep(200);
    }
    switch ($task) {
        case 'login':
            echo reserve_login_attempt($key) === null ? 'LOCKED' : 'OK';
            break;
        case 'rate':
            echo rate_limited($key, 5, 60) ? 'BLOCKED' : 'PASS';
            break;
        default:
            echo 'ERR unknown task';
    }
} catch (Throwable $e) {
    echo 'ERR ' . get_class($e) . ': ' . $e->getMessage();
}
