<?php
// Copy this file to db-config.php (same folder) and fill in real values.
// db-config.php must NEVER be committed to git — it is gitignored on purpose.

define('DB_HOST', 'localhost');
define('DB_NAME', 'your_database_name');
define('DB_USER', 'your_database_user');
define('DB_PASS', 'your_database_password');

// Absolute filesystem path to the off-webroot folder for prescriptions/documents.
// This must sit OUTSIDE public_html so the FTPS deploy (which only syncs public_html/)
// never touches it. Example on Hostinger: '/home/u818223526/domains/kuronyx.in/private_storage'
define('PRIVATE_STORAGE_PATH', '');
