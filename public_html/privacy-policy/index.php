<?php
// The Privacy Policy at a clean address, /privacy-policy/ (it used to be only /Privacy%20Policy.html). It serves the SAME
// file: the policy is not copied, and the file is not edited, because the "Privacy Policy version" stored with every
// consent is a fingerprint of that file (privacy_policy_version() in includes/data-protection.php) - an edit, even a
// canonical tag, would start a new version. Two things are added to the page on its way out, not to the file: a base
// address, because the policy's own links are relative ("./index.html", Back to site) and would otherwise point inside
// /privacy-policy/, and a canonical tag for this address. The old address is redirected here in .htaccess.
$file = __DIR__ . '/../Privacy Policy.html';
$html = is_file($file) ? file_get_contents($file) : false;
if ($html === false) {
    http_response_code(404);
    exit('Page not found.');
}
$html = preg_replace('/<head>/', "<head>\n<base href=\"/\">\n<link rel=\"canonical\" href=\"https://kuronyx.in/privacy-policy/\">", $html, 1);
header('Content-Type: text/html; charset=utf-8');
echo $html;