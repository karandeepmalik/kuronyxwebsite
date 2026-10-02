<?php
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden.');
}

// Locates db-config.php. Preferred location is one level above public_html (e.g.
// /domains/kuronyx.in/db-config.php on Hostinger) so the credentials are never inside the
// webroot at all — a PHP-handler or .htaccess failure can then never serve them as plain
// text. Falls back to includes/db-config.php (the old location) so existing installs and
// local dev/tests keep working until the file is moved. Returns null if neither exists.
function gs_config_path(): ?string {
    foreach ([dirname(__DIR__, 2) . '/db-config.php', __DIR__ . '/db-config.php'] as $path) {
        if (is_file($path)) {
            return $path;
        }
    }
    return null;
}
