<?php
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden.');
}

// Locates db-config.php. Preferred location is one level above public_html (e.g.
// /domains/kuronyx.in/db-config.php on Hostinger) so the credentials are never inside the
// webroot at all - a PHP-handler or .htaccess failure can then never serve them as plain
// text. Falls back to includes/db-config.php (the old location) so existing installs and
// local dev/tests keep working until the file is moved. Returns null if neither exists.
//
// The fallback is NEVER refused (local dev and the tests rely on it), but it is no longer silent, because the
// dangerous state is the one where nobody notices: the real file moved above public_html and a stale copy
// (real passwords, maybe old ones) still sitting inside the web folder. The loop below would happily use the
// safe one and everything would look fine. So a line goes to the error log when:
//   - BOTH files exist: loud ("SECURITY"), always, even on a developer's machine - delete includes/db-config.php
//     on the server, not just from the repo;
//   - only the includes/ copy exists and this is not a local request (localhost, or a command-line run): it should be
//     moved above public_html.
// Once per request (this function is called from more than one place). Only paths are logged, never contents.
function gs_config_is_local_request(): bool {
    $host = strtolower((string) ($_SERVER['SERVER_NAME'] ?? ''));
    if ($host === '') return true; // no server name: a command-line run (test workers, scripts), not a live web request
    return in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true) || str_ends_with($host, '.localhost');
}

function gs_config_path(): ?string {
    static $warned = false;
    $above  = dirname(__DIR__, 2) . '/db-config.php';
    $inside = __DIR__ . '/db-config.php';
    $aboveFound  = is_file($above);
    $insideFound = is_file($inside);

    if (!$warned) {
        if ($aboveFound && $insideFound) {
            error_log('[config] SECURITY: db-config.php exists in BOTH places. The site uses the safe one above public_html (' . $above . '), '
                . 'but a second copy sits inside the web folder (' . $inside . '). Delete that copy from the server now.');
            $warned = true;
        } elseif ($insideFound && !gs_config_is_local_request()) {
            error_log('[config] db-config.php is being read from inside the web folder (' . $inside . '). Move it one level above public_html '
                . '(' . $above . ') and delete this copy.');
            $warned = true;
        }
    }

    if ($aboveFound) return $above;
    return $insideFound ? $inside : null;
}
