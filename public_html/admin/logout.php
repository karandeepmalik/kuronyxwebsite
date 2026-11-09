<?php
require __DIR__ . '/../includes/auth.php';
// POST + CSRF only — as a plain GET link, any page on the internet could log a staff
// member out by embedding it as an <img>/<iframe>/link prefetch.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    header('Location: /admin/gs-requests/');
    exit;
}
$staff = current_staff();
if ($staff) {
    audit('staff_logout', 'staff_user', $staff['id'], []);
}
sign_out_and_clear_browser_cache(); // destroys the session, expires its cookie and asks the browser to drop cached pages
header('Location: /admin/login.php');
exit;
