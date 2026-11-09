<?php
// Runs erase_gs_request() / delete_erasure_leftovers() in a separate PHP process (its own database
// connection, released when it exits - a connection held by the test process itself can stall the
// HTTP test server on a Windows SQLite file), with a file deleter that FAILS for the named files and
// really deletes the rest. That is the only way to exercise "a delete failed" on every platform:
// making unlink() fail for real depends on the OS and on file permissions.
//
// Usage: php erasure_worker.php <erase|erase_vet|leftovers> <case/application id, or 0> <comma-separated filenames that must fail>
// Prints one JSON result (or "ERR ...").
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

[, $task, $caseId, $failNames] = $argv + [null, '', '0', ''];
$_SERVER['SCRIPT_NAME'] = '/erasure_worker.php';
require __DIR__ . '/../../public_html/includes/data-protection.php';

try {
    $failing = array_values(array_filter(explode(',', (string) $failNames), 'strlen'));
    $deleter = function (string $path) use ($failing): bool {
        return in_array(basename($path), $failing, true) ? false : @unlink($path);
    };
    switch ($task) {
        case 'erase':
            echo json_encode(erase_gs_request(db(), (int) $caseId, $deleter));
            break;
        case 'erase_vet':
            echo json_encode(erase_vet_application(db(), (int) $caseId, $deleter));
            break;
        case 'leftovers':
            echo json_encode(delete_erasure_leftovers(db(), $deleter));
            break;
        default:
            echo 'ERR unknown task';
    }
} catch (Throwable $e) {
    echo 'ERR ' . get_class($e) . ': ' . $e->getMessage();
}
