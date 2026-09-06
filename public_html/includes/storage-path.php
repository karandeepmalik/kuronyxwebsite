<?php
require_once __DIR__ . '/db-config.php';

// Absolute path to the off-webroot document store. Falls back to a local
// folder (still outside public_html) only when PRIVATE_STORAGE_PATH hasn't
// been configured yet, so local development doesn't hard-fail.
function private_storage_path(): string {
    if (defined('PRIVATE_STORAGE_PATH') && PRIVATE_STORAGE_PATH !== '') {
        return rtrim(PRIVATE_STORAGE_PATH, '/');
    }
    $fallback = dirname(__DIR__, 2) . '/private_storage_fallback';
    if (!is_dir($fallback)) {
        @mkdir($fallback, 0750, true);
    }
    return realpath($fallback) ?: $fallback;
}
