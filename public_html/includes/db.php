<?php
// Not a page — refuse direct requests even if .htaccess's deny-all somehow doesn't apply.
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden.');
}

// db-config.php is created manually on the server (never committed) and won't exist
// right after a fresh deploy — fail closed with a plain 503 instead of a raw PHP
// "failed to open required file" fatal error.
if (!file_exists(__DIR__ . '/db-config.php')) {
    http_response_code(503);
    exit('Site is not yet configured. Please try again shortly.');
}
require_once __DIR__ . '/db-config.php';

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
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
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
