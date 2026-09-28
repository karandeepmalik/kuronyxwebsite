<?php
// Brevo secrets and settings now live in ../includes/db-config.php (gitignored) —
// see includes/db-config.sample.php. This file exists only so send-welcome.php /
// send-enquiry.php's `require_once 'config.php'` keeps working unchanged.
if (!file_exists(__DIR__ . '/../includes/db-config.php')) {
    http_response_code(503);
    exit('Site is not yet configured. Please try again shortly.');
}
require_once __DIR__ . '/../includes/db-config.php';
