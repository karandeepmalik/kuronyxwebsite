<?php
// Copy this file to db-config.php and fill in real values. PREFERRED location: one level
// ABOVE public_html (e.g. /domains/kuronyx.in/db-config.php on Hostinger), so credentials are
// never inside the webroot. Falling back to this folder still works (local dev/tests).
// db-config.php must NEVER be committed to git — it is gitignored on purpose.
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden.');
}

define('DB_HOST', 'localhost');
define('DB_NAME', 'your_database_name');
define('DB_USER', 'your_database_user');
define('DB_PASS', 'your_database_password');

// Absolute filesystem path to the off-webroot folder for prescriptions/documents.
// This must sit OUTSIDE public_html so the FTPS deploy (which only syncs public_html/)
// never touches it. Example on Hostinger: '/home/u818223526/domains/kuronyx.in/private_storage'
define('PRIVATE_STORAGE_PATH', '');

// Absolute path to a private, off-webroot folder for PHP session files (sibling of the
// storage folder above, e.g. '/home/u818223526/domains/kuronyx.in/private_sessions').
// Optional — falls back to a local ../session_fallback folder if unset. PHP's default
// session directory is the shared system temp dir, which on shared hosting other accounts
// may be able to read.
// define('SESSION_SAVE_PATH', '');

// Set to true ONLY after confirming that production really sits behind a proxy/CDN that
// sets X-Forwarded-For (check with a temporary page printing $_SERVER['REMOTE_ADDR'] and
// $_SERVER['HTTP_X_FORWARDED_FOR'], then delete it). If REMOTE_ADDR is the same address for
// every visitor, every per-IP rate limit in the app collapses into one site-wide limit —
// that's the sign this is needed. Never enable it without a proxy actually in front: the
// header is attacker-controlled otherwise and would let anyone bypass every per-IP limit.
// define('TRUST_PROXY_IP_HEADER', true);

// Required to use /admin/setup.php (e.g. https://kuronyx.in/admin/setup.php?token=...).
// Generate a long random value yourself, e.g. run this once locally:
//   php -r "echo bin2hex(random_bytes(32));"
// Without a matching ?token= the page returns 403, regardless of whether a staff
// account already exists — this is a permanent, predictable URL and must not be
// left reachable by anyone who simply finds it.
define('SETUP_TOKEN', '');

// Brevo transactional email. This is the single source of truth for the Brevo API
// key — public_html/php/config.php (used by the newsletter/enquiry forms) and the
// admin email composer / automatic case notifications both read it from here.
// Get the key from Brevo → Settings → SMTP & API → API Keys.
define('BREVO_API_KEY', '');
define('BREVO_TEMPLATE_ID', 1);          // welcome-email template (newsletter subscribe)
define('BREVO_ENQUIRY_TEMPLATE_ID', 3);  // contact-us enquiry template
define('BREVO_LIST_ID', 7);              // newsletter contact list

// Public base URL used in links the app emails out (password reset). Optional — defaults
// to https://kuronyx.in, so production doesn't need it. Set it only for other
// environments, e.g. http://localhost:8000 for local dev.
// define('SITE_URL', 'https://kuronyx.in');

// Shared secret for the Brevo delivery webhook (php/webhook.php). Generate one with
//   php -r "echo bin2hex(random_bytes(24));"
// and configure the SAME value as a `secret` query parameter on the webhook URL you
// register in Brevo (Settings → Webhooks), e.g.
//   https://kuronyx.in/php/webhook.php?secret=<this value>
// Without this, anyone on the internet could POST fake "delivered" events to add
// arbitrary emails to your contact list, or POST anything at all and have it logged.
define('BREVO_WEBHOOK_SECRET', '');
define('SENDER_EMAIL', 'hello@kuronyx.in');
define('RECEIVER_EMAIL', 'ops@kuronyx.in');
define('SENDER_NAME', 'Kuronyx Sciences');

// Addresses staff can pick as the "from" in the admin email composer (gs-requests and
// veterinary-applications). Keep this in sync with whatever is actually verified in
// Brevo (Settings → Senders & IP / domain authentication) — sending as an address not
// verified there will fail, and mailer.php's send_transactional_email() refuses any
// address that isn't a key in this list even if someone tampers with the form.
define('BREVO_VERIFIED_SENDERS', [
    'hello@kuronyx.in'  => 'Kuronyx Sciences',
    'orders@kuronyx.in' => 'Kuronyx Sciences Orders',
]);

// Set to true only in local/test environments to skip real Brevo API calls from the
// admin email composer (the test suite sets this itself — see tests/lib/server.php).
// Leave false in production.
define('EMAIL_DRY_RUN', false);

// --- Optional abuse-protection settings (all have safe defaults) ---

// Cloudflare Turnstile CAPTCHA on the two anonymous upload forms (/for-cat-owners and
// /for-veterinarians/apply). Free; create a widget at dash.cloudflare.com -> Turnstile
// (the site does NOT need to be on Cloudflare). Leave both unset to run without a CAPTCHA.
// define('TURNSTILE_SITE_KEY', '');
// define('TURNSTILE_SECRET_KEY', '');

// How many months after a finished/closed/cancelled/rejected case was last touched it appears on
// the admin Data Erasure page's review list (nothing is deleted automatically). 36 is only a
// placeholder: confirm how long prescription/dispensing records must legally be kept first.
// define('RETENTION_MONTHS', 36);
