<?php
// Not a page — refuse direct requests even if .htaccess's deny-all somehow doesn't apply.
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden.');
}

// db-config.php is created manually on the server (never committed) and won't exist
// right after a fresh deploy — fail closed with a plain 503 instead of a raw PHP
// "failed to open required file" fatal error.
require_once __DIR__ . '/config-loader.php';
$__configPath = gs_config_path();
if ($__configPath === null) {
    http_response_code(503);
    exit('Site is not yet configured. Please try again shortly.');
}
require_once $__configPath;
unset($__configPath);

// A POST body larger than post_max_size is silently discarded by PHP — $_POST/$_FILES
// arrive empty — so the first thing every form notices is a missing CSRF token and
// reports "your session expired", which sends the user hunting for the wrong problem
// (and, for a long dispatch article or a big upload, loses what they typed). Say what
// actually happened instead.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && empty($_POST) && empty($_FILES)) {
    $__limit = trim((string) ini_get('post_max_size'));
    $__bytes = (int) $__limit;
    switch (strtolower(substr($__limit, -1))) {
        case 'g': $__bytes *= 1024; // no break
        case 'm': $__bytes *= 1024; // no break
        case 'k': $__bytes *= 1024;
    }
    if ($__bytes > 0 && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $__bytes) {
        http_response_code(413);
        exit('The submitted data was too large (limit ' . $__limit . '). Please go back and try again with smaller files.');
    }
}
unset($__limit, $__bytes);

// No form or query string anywhere in this app uses array-valued parameters (name="x[]"
// / ?q[]=), but PHP happily builds one from any request — and the many trim($_POST['x'])
// style reads throughout would then throw a TypeError (a 500) on it. Rejecting it once,
// centrally, up front is safer than guarding each of those reads individually and
// missing one.
foreach ([$_GET, $_POST] as $__params) {
    foreach ($__params as $__value) {
        if (is_array($__value)) {
            http_response_code(400);
            exit('Invalid request.');
        }
    }
}
unset($__params, $__value);

// Never let a raw exception (DB error, etc.) reach the browser — that leaks file
// paths, queries and stack traces. Log the real error server-side, show nothing
// specific to the visitor. Applies site-wide since every dynamic page requires this file.
ini_set('display_errors', '0');
set_exception_handler(function (Throwable $e): void {
    error_log('[GS-441524] Uncaught ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
    }
    echo '<!doctype html><html><head><title>Something went wrong</title></head>'
       . '<body style="font-family:sans-serif;background:#22384A;color:#F5F5F5;padding:3rem;">'
       . '<h1>Something went wrong.</h1><p>Please try again shortly. If this continues, contact '
       . '<a href="mailto:ops@kuronyx.in" style="color:#58959D;">ops@kuronyx.in</a>.</p></body></html>';
});

// Formats a stored UTC timestamp for display in India time (IST). Stored values stay
// UTC everywhere; only what a person reads on screen is converted.
function fmt_time(?string $utc): string {
    if ($utc === null || $utc === '') return '—';
    try {
        $d = new DateTime($utc, new DateTimeZone('UTC'));
        $d->setTimezone(new DateTimeZone('Asia/Kolkata'));
        return $d->format('d M Y, H:i');
    } catch (Throwable $e) {
        return $utc;
    }
}

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        // DB_DRIVER is optional and defaults to 'mysql' (production). 'sqlite' exists only
        // so this app can be exercised on a local dev machine without a MySQL server.
        $driver = defined('DB_DRIVER') ? DB_DRIVER : 'mysql';
        if ($driver === 'sqlite') {
            $dsn = 'sqlite:' . DB_NAME;
            $pdo = new PDO($dsn);
            $pdo->exec('PRAGMA foreign_keys = ON');
        } else {
            $dsn = 'mysql:host=' . DB_HOST . (defined('DB_PORT') ? ';port=' . (int) DB_PORT : '') . ';dbname=' . DB_NAME . ';charset=utf8mb4';
            $pdo = new PDO($dsn, DB_USER, DB_PASS);
            // Every stored timestamp is UTC (SQLite's CURRENT_TIMESTAMP already is, and
            // the app's own gmdate() calls are). Without this, MySQL stamps rows in
            // whatever timezone the shared host happens to run in.
            $pdo->exec("SET time_zone = '+00:00'");
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }
    return $pdo;
}
