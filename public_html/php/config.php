<?php
// Brevo secrets and settings live in db-config.php, preferably one level above public_html
// (see includes/config-loader.php and includes/db-config.sample.php). This file exists only
// so send-welcome.php / send-enquiry.php's `require_once 'config.php'` keeps working unchanged.
require_once __DIR__ . '/../includes/config-loader.php';
$__configPath = gs_config_path();
if ($__configPath === null) {
    http_response_code(503);
    exit('Site is not yet configured. Please try again shortly.');
}
require_once $__configPath;
unset($__configPath);
