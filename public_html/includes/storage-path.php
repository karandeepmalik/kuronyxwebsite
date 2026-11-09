<?php
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden.');
}

require_once __DIR__ . '/db.php';

// Thrown by private_storage_path() on a LIVE host when PRIVATE_STORAGE_PATH is not set. db.php's exception handler
// answers it with a plain 503 ("temporarily unavailable"), so nothing is saved to the wrong place.
class StorageNotConfiguredException extends RuntimeException {}

// Absolute path to the off-webroot document store.
//
// A missing PRIVATE_STORAGE_PATH used to fall back SILENTLY to a private_storage_fallback folder, on the live server
// too (a Karandeep review comment): after a config rewrite or a fresh deploy that dropped the setting, new prescriptions
// would quietly go into a second folder, split from the old ones - downloads look in whichever folder is configured at
// that moment (so documents "disappear" for staff), erasure only deletes from the configured one, and the second folder
// may not be in the backups. So the fallback is now only for local work (localhost, 127.0.0.1, *.localhost, a command-line
// run: gs_config_is_local_request() in config-loader.php); on any other host it is REFUSED, loudly (error log + 503),
// and the fix is one line in db-config.php. Local dev and the tests set their own path or use the fallback as before.
function private_storage_path(): string {
    if (defined('PRIVATE_STORAGE_PATH') && PRIVATE_STORAGE_PATH !== '') {
        return rtrim(PRIVATE_STORAGE_PATH, '/');
    }
    if (!gs_config_is_local_request()) {
        static $logged = false;
        if (!$logged) {
            $logged = true; // once per request: this function is called several times while storing one upload
            error_log('[storage] PRIVATE_STORAGE_PATH is not set on a live host, so the fallback folder is REFUSED (uploads and documents are unavailable '
                . 'until it is set in db-config.php). Using it would split prescriptions across two folders.');
        }
        throw new StorageNotConfiguredException('PRIVATE_STORAGE_PATH is not configured.');
    }
    // dirname(__DIR__, 2) is the folder ABOVE public_html only because includes/ sits directly inside public_html.
    $fallback = dirname(__DIR__, 2) . '/private_storage_fallback';
    if (!is_dir($fallback)) {
        @mkdir($fallback, 0750, true);
    }
    return realpath($fallback) ?: $fallback;
}
