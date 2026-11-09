<?php
// Tells the static pages (index.html) and js/captcha.js whether Turnstile is switched on, and
// the public site key to render it with. The site key is public by design; the secret key
// never leaves db-config.php. With no TURNSTILE_* keys configured this returns an empty
// object and the forms behave exactly as before.
require_once 'config.php';

header('Content-Type: application/json');
header('Cache-Control: public, max-age=300');
$siteKey = defined('TURNSTILE_SITE_KEY') && defined('TURNSTILE_SECRET_KEY')
    && TURNSTILE_SITE_KEY !== '' && TURNSTILE_SECRET_KEY !== '' ? TURNSTILE_SITE_KEY : null;
echo json_encode($siteKey ? ['siteKey' => $siteKey] : new stdClass());
